<?php

declare(strict_types=1);

use App\Assistant\RunContext;
use App\Assistant\Tools\SearchRecords;
use App\Assistant\Tools\ToolRegistry;
use App\Assistant\Tools\ToolResult;
use App\Models\Record;

/** @param array<string, mixed> $input */
function runTool(string $name, array $input, ?RunContext $context = null): ToolResult
{
    return app(ToolRegistry::class)->run($name, $input, $context ?? RunContext::start());
}

it('defines every tool with a strict JSON schema', function () {
    foreach (app(ToolRegistry::class)->definitions() as $definition) {
        expect($definition['strict'])->toBeTrue()
            ->and($definition['inputSchema']['type'])->toBe('object')
            ->and($definition['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($definition['inputSchema']['required'])->toBe(array_keys($definition['inputSchema']['properties']))
            ->and($definition['description'])->not->toBeEmpty();
    }
});

it('finds records by keyword, best match first, and marks them returned', function () {
    $context = RunContext::start();

    $result = runTool('search_records', ['query' => 'attendance visitors Thursday', 'department' => 'any'], $context);

    expect($result->isError)->toBeFalse()
        ->and($result->returnedRecordIds[0])->toBe('REC-0010')
        ->and($result->returnedRecordIds)->toContain('REC-0003')
        ->and($context->wasReturned('REC-0010'))->toBeTrue()
        ->and($context->wasReturned('REC-0001'))->toBeFalse();
});

it('limits a search to one department', function () {
    $result = runTool('search_records', ['query' => 'Willowbend', 'department' => 'parks']);

    expect($result->returnedRecordIds)->toBe(['REC-0004', 'REC-0009']);

    expect(runTool('search_records', ['query' => 'Willowbend', 'department' => 'police'])->content)
        ->toBe('No records matched "Willowbend".');
});

it('returns at most five matches', function () {
    Record::factory()->count(8)->create(['body' => 'Synthetic minutes about the gazebo.']);

    expect(runTool('search_records', ['query' => 'gazebo', 'department' => 'any'])->returnedRecordIds)
        ->toHaveCount(SearchRecords::MAX_RESULTS);
});

it('treats LIKE wildcards in a query as literal characters', function () {
    expect(runTool('search_records', ['query' => '%% __', 'department' => 'any'])->content)
        ->toBe('No records matched "%% __".');

    Record::factory()->create(['id' => 'REC-0500', 'body' => 'The levy rose by 100% this year.']);

    expect(runTool('search_records', ['query' => '100%', 'department' => 'any'])->returnedRecordIds)->toBe(['REC-0500']);
});

it('rejects invalid search input as a tool error', function (array $input, string $message) {
    $result = runTool('search_records', $input);

    expect($result->isError)->toBeTrue()->and($result->content)->toContain($message);
})->with([
    'missing query' => [['department' => 'any'], 'The query field is required.'],
    'too short' => [['query' => 'a', 'department' => 'any'], 'The query field must be at least 2 characters.'],
    'one-letter words only' => [['query' => 'a b', 'department' => 'any'], 'needs at least one word of two or more characters'],
    'unknown department' => [['query' => 'park', 'department' => 'treasury'], 'The selected department is invalid.'],
    'not a string' => [['query' => ['park'], 'department' => 'any'], 'The query field must be a string.'],
]);

it('returns a full record inside its delimiter', function () {
    $result = runTool('get_record', ['record_id' => 'REC-0008']);

    expect($result->content)->toBe(implode("\n", [
        'record_id: REC-0008',
        'title: Snow removal budget summary, winter 2025-26',
        'department: public_works',
        'filed_on: 2026-04-30',
        '<record_text record_id="REC-0008">',
        'Total snow removal spending: $182,400 against a budget of $170,000. Overtime accounted for $41,200. Salt usage: 1,150 tonnes.',
        '</record_text>',
    ]))->and($result->returnedRecordIds)->toBe(['REC-0008']);
});

it('rejects a malformed record id before querying', function () {
    $result = runTool('get_record', ['record_id' => "REC-0001' or 1=1 --"]);

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toBe('Invalid input: The record id field format is invalid.')
        ->and($result->returnedRecordIds)->toBe([]);
});

it('accepts a quote the model copied in escaped form', function () {
    Record::factory()->create(['id' => 'REC-0600', 'body' => 'Contact: Sam <sam@example.org> & family.']);

    $result = runTool('propose_redaction', [
        'record_id' => 'REC-0600',
        'redactions' => [['text' => 'Sam &lt;sam@example.org&gt; &amp; family', 'reason' => 'personal_contact']],
        'rationale' => 'Removes a contact.',
    ]);

    expect($result->isError)->toBeFalse();
});

it('rejects too many redactions', function () {
    $result = runTool('propose_redaction', [
        'record_id' => 'REC-0004',
        'redactions' => array_fill(0, 21, ['text' => 'Jordan Placeholder', 'reason' => 'personal_identifier']),
        'rationale' => 'Too many.',
    ]);

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('The redactions field must not have more than 20 items.');
});
