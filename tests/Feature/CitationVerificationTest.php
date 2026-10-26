<?php

declare(strict_types=1);

use App\Assistant\CitationVerifier;
use App\Assistant\FinalAnswer;
use App\Assistant\RunContext;
use App\Enums\AnswerStatus;

it('fails an answer that cites a missing id, an id no tool returned, or mentions an id it did not cite', function () {
    claude('pothole_search', 'bad_citations_final');

    $result = ask('How many potholes were filled?');

    expect($result->status)->toBe(AnswerStatus::CitationsFailed)
        ->and($result->citationProblems)->toBe([
            'Cited REC-0004, which no tool returned in this run.',
            'Cited REC-0999, which does not exist.',
            'Mentioned REC-0002 in the answer without citing it.',
        ]);
});

it('counts an id as returned only once a tool actually returned it', function () {
    // pothole_search returns REC-0001; the final answer cites only REC-0001.
    claude('pothole_search', 'pothole_final');

    expect(ask('How many potholes were filled?')->status)->toBe(AnswerStatus::Verified);
});

it('does not count an id the model asked for when the tool said it does not exist', function () {
    claude('missing_get', 'streetlight_search', 'streetlight_final');

    $result = ask('Were the streetlights fixed?');

    expect($result->status)->toBe(AnswerStatus::Verified)
        ->and($result->citedRecordIds)->not->toContain('REC-0999');
});

it('catches a mentioned id written in lower case, with a space or with no separator', function (string $mention) {
    $context = RunContext::start();
    $context->markReturned(['REC-0001']);

    $problems = app(CitationVerifier::class)->problems(new FinalAnswer("Four potholes (REC-0001); see also {$mention}.", ['REC-0001']), $context);

    expect($problems)->toBe(['Mentioned REC-0004 in the answer without citing it.']);
})->with(['rec-0004', 'REC 0004', 'Rec0004', 'REC‑0004 (non-breaking hyphen)']);

it('accepts a cited id however the answer writes it', function () {
    $context = RunContext::start();
    $context->markReturned(['REC-0001']);

    expect(app(CitationVerifier::class)->problems(new FinalAnswer('See rec 0001.', ['REC-0001']), $context))->toBe([]);
});
