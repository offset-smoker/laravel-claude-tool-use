<?php

declare(strict_types=1);

namespace Tests;

use Anthropic\Client;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FixtureTransport;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Usage logs go nowhere unless a test captures them.
        config(['claude.usage_log_channel' => 'null']);

        // Every test talks to the fixture transport, never to the network.
        $transport = new FixtureTransport;
        $this->app->instance(FixtureTransport::class, $transport);
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key-not-real',
            requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
        ));
    }
}
