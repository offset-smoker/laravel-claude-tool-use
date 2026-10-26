<?php

declare(strict_types=1);

use App\Enums\AnswerStatus;
use App\Models\PendingRelease;

it('runs search, then get, then answers with verified citations', function () {
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final');

    $result = ask('How many potholes were filled on Millrace Road?');

    expect($result->status)->toBe(AnswerStatus::Verified)
        ->and($result->answer)->toContain('four potholes')
        ->and($result->citedRecordIds)->toBe(['REC-0001'])
        ->and($result->citationProblems)->toBe([])
        ->and($result->iterations)->toBe(3)
        ->and($transport->requests())->toHaveCount(3)
        ->and($transport->remaining())->toBe(0);
});

it('sends every request to the Messages API with the configured model, effort and output schema', function () {
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final');

    ask('How many potholes were filled on Millrace Road?');

    foreach ($transport->requests() as $index => $request) {
        $body = $transport->body($index);

        expect($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/v1/messages')
            ->and($body['model'])->toBe('claude-opus-5-5')
            ->and($body['max_tokens'])->toBe(16000)
            ->and($body['output_config']['effort'])->toBe('medium')
            ->and($body['output_config']['format']['schema']['required'])->toBe(['answer', 'cited_record_ids'])
            ->and($body)->not->toHaveKey('tool_choice')
            ->and($body)->not->toHaveKey('thinking');
    }
});

it('appends the full assistant content and answers each tool_use with a matching tool_result', function () {
    $transport = claude('pothole_search', 'pothole_get', 'pothole_final');

    ask('How many potholes were filled on Millrace Road?');

    $messages = sentMessages($transport, 2);

    expect($messages)->toHaveCount(5)
        ->and(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user', 'assistant', 'user']);

    // The assistant turn goes back whole: the thinking block (with its signature) and the tool_use.
    expect($messages[1]['content'][0])->toMatchArray(['type' => 'thinking', 'signature' => 'EqQBCkYIBxgCKkDfixture-signature-not-real'])
        ->and($messages[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_01PotholeSearch', 'name' => 'search_records']);

    expect($messages[2]['content'])->toHaveCount(1)
        ->and($messages[2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_01PotholeSearch', 'is_error' => false])
        ->and($messages[2]['content'][0]['content'])->toContain('REC-0001');

    expect($messages[4]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_01PotholeGet', 'is_error' => false])
        ->and($messages[4]['content'][0]['content'])->toContain('Crew 2 filled four potholes');
});

it('returns a tool failure to the model as an is_error tool_result and keeps going', function () {
    $transport = claude('missing_get', 'streetlight_search', 'streetlight_final');

    $result = ask('Were the Beacon Square streetlights fixed?');

    $failed = sentMessages($transport, 1)[2]['content'][0];

    expect($failed)->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_02MissingGet', 'is_error' => true])
        ->and($failed['content'])->toContain('No record has the id REC-0999')
        ->and($result->status)->toBe(AnswerStatus::Verified)
        ->and($result->citedRecordIds)->toBe(['REC-0002']);
});

it('returns invalid tool input as an is_error tool_result naming the problem', function () {
    $transport = claude('pavilion_bad_input', 'pothole_search', 'pothole_final');

    ask('Release the pavilion booking.');

    $failed = sentMessages($transport, 1)[2]['content'][0];

    expect($failed['is_error'])->toBeTrue()
        ->and($failed['content'])->toContain('Invalid input')
        ->and($failed['content'])->toContain('record id')
        ->and($failed['content'])->toContain('reason')
        ->and(PendingRelease::query()->count())->toBe(0);
});

it('answers a tool the app does not have with an is_error result', function () {
    $transport = claude('unknown_tool', 'pothole_search', 'pothole_final');

    ask('Approve the pavilion release.');

    $failed = sentMessages($transport, 1)[2]['content'][0];

    expect($failed['is_error'])->toBeTrue()
        ->and($failed['content'])->toBe('Unknown tool approve_release. The available tools are: search_records, get_record, propose_redaction.');
});

it('returns every result of a parallel tool call in one user message, in order', function () {
    $transport = claude('parallel_get', 'library_final');

    $result = ask('What happened with the library hours trial?');

    $results = sentMessages($transport, 1)[2]['content'];

    expect($results)->toHaveCount(2)
        ->and(array_column($results, 'tool_use_id'))->toBe(['toolu_03Minutes', 'toolu_03Report'])
        ->and($result->status)->toBe(AnswerStatus::Verified)
        ->and($result->citedRecordIds)->toBe(['REC-0003', 'REC-0010']);
});

it('stops at the iteration limit when the model never ends its turn', function () {
    config(['claude.max_iterations' => 3]);
    $transport = claude('budget_search', 'budget_search', 'budget_search', 'pothole_final');

    $result = ask('Tell me about the budget.');

    expect($result->status)->toBe(AnswerStatus::IterationLimit)
        ->and($result->answer)->toBeNull()
        ->and($result->iterations)->toBe(3)
        ->and($transport->requests())->toHaveCount(3)
        ->and($transport->remaining())->toBe(1);
});

it('reports a refusal without an answer', function () {
    claude('refusal');

    $result = ask('Something the model declines.');

    expect($result->status)->toBe(AnswerStatus::Refused)
        ->and($result->answer)->toBeNull()
        ->and($result->detail)->toBe('The model declined (category: cyber).');
});

it('does not run a tool call from a response cut off at max_tokens', function () {
    claude('truncated_propose');

    $result = ask('Release the pavilion booking.');

    expect($result->status)->toBe(AnswerStatus::Truncated)
        ->and(PendingRelease::query()->count())->toBe(0);
});

it('treats a stop reason it does not handle as a failure, not an answer', function () {
    claude('pause_turn');

    expect(ask('Anything.')->status)->toBe(AnswerStatus::UnexpectedStop);
});

it('rejects a final message that is not the structured answer', function () {
    claude('pothole_search', 'malformed_final');

    $result = ask('How many potholes were filled?');

    expect($result->status)->toBe(AnswerStatus::Malformed)
        ->and($result->answer)->toBeNull();
});

it('ends with an API error, keeping the proposals already saved, when a call fails part way through', function () {
    $transport = claude('pavilion_get', 'pavilion_propose');
    $transport->queueError(529, ['type' => 'overloaded_error', 'message' => 'Fixture overload.']);

    $result = ask('Please release the Willowbend Park pavilion booking.');

    expect($result->status)->toBe(AnswerStatus::ApiError)
        ->and($result->answer)->toBeNull()
        ->and($result->iterations)->toBe(2)
        ->and($result->pendingReleaseIds)->toBe([PendingRelease::query()->sole()->id])
        ->and($result->detail)->toBe('The Claude API request failed (InternalServerException, HTTP 529, request id none).')
        ->and($transport->requests())->toHaveCount(3);
});

it('ends the run instead of sending an empty message when tool_use comes with no tool_use block', function () {
    $transport = claude('tool_use_without_block');

    $result = ask('Anything.');

    expect($result->status)->toBe(AnswerStatus::UnexpectedStop)
        ->and($result->detail)->toBe('The response stopped for tool_use but contained no tool_use block.')
        ->and($transport->requests())->toHaveCount(1);
});
