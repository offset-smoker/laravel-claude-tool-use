<?php

declare(strict_types=1);

use App\Assistant\FinalAnswer;

it('parses the structured answer and drops duplicate ids', function () {
    $answer = FinalAnswer::parse('{"answer":"Yes (REC-0001).","cited_record_ids":["REC-0001","REC-0001","REC-0002"]}');

    expect($answer?->answer)->toBe('Yes (REC-0001).')
        ->and($answer?->citedRecordIds)->toBe(['REC-0001', 'REC-0002']);
});

it('rejects anything that is not the schema', function (string $json) {
    expect(FinalAnswer::parse($json))->toBeNull();
})->with([
    'prose' => ['The answer is yes.'],
    'truncated' => ['{"answer":"Yes'],
    'missing ids' => ['{"answer":"Yes"}'],
    'ids not a list' => ['{"answer":"Yes","cited_record_ids":"REC-0001"}'],
    'non-string id' => ['{"answer":"Yes","cited_record_ids":[1]}'],
    'answer not a string' => ['{"answer":["Yes"],"cited_record_ids":[]}'],
    'array not object' => ['[]'],
]);

it('describes a strict JSON schema for the output config', function () {
    expect(FinalAnswer::schema()['additionalProperties'])->toBeFalse()
        ->and(FinalAnswer::schema()['required'])->toBe(['answer', 'cited_record_ids']);
});
