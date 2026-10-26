<?php

declare(strict_types=1);

use App\Assistant\AssistantResult;
use App\Assistant\ToolUseLoop;
use Database\Seeders\RecordSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixtureTransport;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RecordSeeder::class))
    ->in('Feature');

/** Queues fixture responses and returns the transport that will serve them. */
function claude(string ...$fixtures): FixtureTransport
{
    return app(FixtureTransport::class)->queue(...$fixtures);
}

function ask(string $question): AssistantResult
{
    return app(ToolUseLoop::class)->ask($question);
}

/**
 * The messages array of request $index, as sent on the wire.
 *
 * @return list<array{role: string, content: mixed}>
 */
function sentMessages(FixtureTransport $transport, int $index): array
{
    /** @var list<array{role: string, content: mixed}> */
    return $transport->body($index)['messages'];
}
