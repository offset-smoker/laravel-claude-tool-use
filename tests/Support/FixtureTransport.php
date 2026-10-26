<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for the Anthropic SDK that answers from fixture files in
 * tests/Fixtures/messages and records every request it is sent. The real SDK
 * builds each request and parses each response, so the tests exercise the
 * actual wire format. It never opens a socket: a request with no fixture
 * queued for it fails the test.
 */
final class FixtureTransport implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function queue(string ...$fixtures): self
    {
        foreach ($fixtures as $fixture) {
            $path = __DIR__.'/../Fixtures/messages/'.$fixture.'.json';
            $body = file_get_contents($path);

            if ($body === false) {
                throw new LogicException("Missing fixture {$path}.");
            }

            $this->queue[] = new Response(200, ['content-type' => 'application/json', 'request-id' => 'req_fixture'], $body);
        }

        return $this;
    }

    /** @param array<string, mixed> $error */
    public function queueError(int $status, array $error): self
    {
        $this->queue[] = new Response($status, ['content-type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => $error,
        ]));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->queue);

        if ($response === null) {
            throw new LogicException('The test made a Messages API request with no fixture queued for it.');
        }

        return $response;
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return array<string, mixed> the decoded JSON body of request $index */
    public function body(int $index): array
    {
        $request = $this->requests[$index] ?? throw new LogicException("No request {$index} was sent.");

        /** @var array<string, mixed> */
        return json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function remaining(): int
    {
        return count($this->queue);
    }
}
