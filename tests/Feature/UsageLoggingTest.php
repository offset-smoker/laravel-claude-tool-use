<?php

declare(strict_types=1);

use App\Models\Record;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

beforeEach(function () {
    config([
        'logging.channels.usage_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'claude.usage_log_channel' => 'usage_capture',
    ]);
});

/** @return list<LogRecord> */
function usageRecords(): array
{
    /** @var Monolog\Logger $logger */
    $logger = Log::channel('usage_capture')->getLogger();

    /** @var TestHandler $handler */
    $handler = $logger->getHandlers()[0];

    return $handler->getRecords();
}

it('logs one entry per model call and one per run, with tokens and an estimated cost', function () {
    claude('pothole_search', 'pothole_get', 'pothole_final');

    $result = ask('How many potholes were filled on Millrace Road?');

    $records = usageRecords();

    expect(array_map(fn (LogRecord $record): string => $record->message, $records))
        ->toBe(['claude.call', 'claude.call', 'claude.call', 'claude.run']);

    // First call: 412 input, 96 output, 1,630 cache write at $4 / $20 / $5 per million.
    // The two later calls each read the 1,630 cached tokens at $0.20 per million instead.
    expect($records[0]->context)->toMatchArray([
        'run_id' => $result->runId,
        'iteration' => 1,
        'model' => 'claude-opus-5-5',
        'stop_reason' => 'tool_use',
        'input_tokens' => 412,
        'output_tokens' => 96,
        'cache_write_tokens' => 1630,
        'cache_read_tokens' => 0,
        'estimated_cost_usd' => 0.011718,
    ]);

    expect($records[3]->context)->toMatchArray([
        'run_id' => $result->runId,
        'status' => 'verified',
        'iterations' => 3,
        'input_tokens' => 1236,
        'output_tokens' => 288,
        'cache_write_tokens' => 1630,
        'cache_read_tokens' => 3260,
        'estimated_cost_usd' => round(0.011718 + 2 * 0.003894, 6),
    ]);
});

it('never logs the question, record text or answer', function () {
    $question = 'Who asked for the Millrace Road pothole repair?';
    claude('pothole_search', 'pothole_get', 'pothole_final');

    $result = ask($question);

    $logged = json_encode(array_map(fn (LogRecord $record): array => $record->toArray(), usageRecords()));

    expect($logged)->not->toContain('Millrace')
        ->not->toContain('pothole')
        ->not->toContain((string) $result->answer)
        ->not->toContain('REC-0001')
        ->not->toContain('test-key-not-real');

    foreach (Record::query()->get() as $record) {
        expect($logged)->not->toContain(mb_substr($record->body, 0, 30));
    }
});

it('logs an unknown cost, rather than a wrong one, for a model with no configured price', function () {
    claude('pothole_search', 'unpriced_model_final');

    $result = ask('How many potholes were filled?');
    $records = usageRecords();

    expect($records[1]->context['model'])->toBe('claude-opus-5')
        ->and($records[1]->context['estimated_cost_usd'])->toBeNull()
        ->and($records[2]->context['estimated_cost_usd'])->toBeNull()
        ->and($result->usage->estimatedCostUsd)->toBeNull();
});
