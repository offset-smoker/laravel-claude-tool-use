<?php

declare(strict_types=1);

use App\Models\Record;

/**
 * The cached prefix is everything up to and including the cache_control
 * breakpoint. The API renders tools, then system, then messages, and the one
 * breakpoint sits on the last system block, so the prefix is tools + system.
 *
 * @param  array<string, mixed>  $body
 */
function cachedPrefix(array $body): string
{
    return (string) json_encode(['tools' => $body['tools'], 'system' => $body['system']]);
}

it('puts the only cache breakpoint on the last system block', function () {
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final');

    ask('How many potholes were filled on Millrace Road?');

    foreach (array_keys($transport->requests()) as $index) {
        $body = $transport->body($index);
        $system = $body['system'];

        expect(end($system)['cache_control'])->toBe(['type' => 'ephemeral'])
            ->and(substr_count((string) json_encode($body), 'cache_control'))->toBe(1)
            ->and($body)->not->toHaveKey('cache_control');
    }
});

it('keeps user data out of the cached prefix', function () {
    $question = 'Who asked for the Millrace Road pothole repair, and how do I reach them?';
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final');

    ask($question);

    $records = Record::query()->get();

    foreach (array_keys($transport->requests()) as $index) {
        $prefix = cachedPrefix($transport->body($index));

        expect($prefix)->not->toContain($question)
            ->not->toContain('Millrace');

        foreach ($records as $record) {
            expect($prefix)->not->toContain($record->title)
                ->not->toContain(mb_substr($record->body, 0, 40));
        }
    }

    // The question and the record text do travel, after the breakpoint, in messages.
    $lastBody = json_encode($transport->body(2)['messages']);
    expect($lastBody)->toContain('Millrace Road pothole repair')->toContain('avery.example@example.org');
});

it('sends a byte-identical prefix for every call and every question', function () {
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final', 'parallel_get', 'library_final');

    ask('How many potholes were filled on Millrace Road?');
    ask('What happened with the library hours trial?');

    $prefixes = array_map(
        fn (int $index): string => cachedPrefix($transport->body($index)),
        array_keys($transport->requests()),
    );

    expect($prefixes)->toHaveCount(5)
        ->and(array_unique($prefixes))->toHaveCount(1);
});
