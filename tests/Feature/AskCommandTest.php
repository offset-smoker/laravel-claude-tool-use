<?php

declare(strict_types=1);

use Anthropic\Client;
use App\Assistant\ClaudeNotConfigured;
use App\Models\PendingRelease;
use Illuminate\Support\Facades\Artisan;

it('prints the verified answer, its citations and the run usage', function () {
    claude('pothole_search', 'pothole_get', 'pothole_final');

    $this->artisan('records:ask', ['question' => 'How many potholes were filled on Millrace Road?'])
        ->expectsOutput('Answer (citations verified)')
        ->expectsOutputToContain('Crew 2 filled four potholes on Millrace Road')
        ->expectsOutput('Cited records: REC-0001')
        ->expectsOutputToContain('3 model call(s); tokens: 1,236 input, 288 output, 1,630 cache write, 3,260 cache read; estimated cost: $0.0195')
        ->assertSuccessful();
});

it('exits non-zero and says why when the citation check fails', function () {
    claude('pothole_search', 'bad_citations_final');

    $this->artisan('records:ask', ['question' => 'How many potholes were filled?'])
        ->expectsOutput('Answer NOT verified: the citation check failed')
        ->expectsOutput('  - Cited REC-0999, which does not exist.')
        ->assertFailed();
});

it('exits non-zero with the reason when there is no answer', function () {
    config(['claude.max_iterations' => 1]);
    claude('budget_search');

    $this->artisan('records:ask', ['question' => 'Tell me about the budget.'])
        ->expectsOutput('No answer: the iteration limit was reached')
        ->expectsOutput('Stopped after 1 model calls without a final answer.')
        ->assertFailed();
});

it('validates the question before calling the model', function (string $question) {
    $transport = claude();

    $this->artisan('records:ask', ['question' => $question])->assertExitCode(2);

    expect($transport->requests())->toBe([]);
})->with([
    'too short' => ['hi'],
    'too long' => [str_repeat('a', 2001)],
]);

it('prints model output without console style tags or terminal escape sequences', function () {
    claude('hostile_output_final');

    Artisan::call('records:ask', ['question' => 'How many potholes were filled?']);
    $output = Artisan::output();

    expect($output)->toContain('<error>Crew 2</error> filled [31mfour[0m potholes (REC-0001).')
        ->not->toContain("\e[31m");
});

it('prints one line, not a stack trace, when no credentials are configured', function () {
    app()->bind(Client::class, fn (): never => throw ClaudeNotConfigured::noCredentials());

    $exit = Artisan::call('records:ask', ['question' => 'How many potholes were filled?']);

    expect($exit)->toBe(1)
        ->and(trim(Artisan::output()))->toBe('No Claude credentials found. Set ANTHROPIC_API_KEY in .env, or sign in with `ant auth login`.');
});

it('checks the question before looking for credentials', function () {
    app()->bind(Client::class, fn (): never => throw ClaudeNotConfigured::noCredentials());

    $exit = Artisan::call('records:ask', ['question' => 'hi']);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('at least 3 characters')
        ->not->toContain('credentials');
});

it('refuses to start with one line when CLAUDE_EFFORT is invalid', function () {
    config(['claude.effort' => 'extreme']);
    $transport = claude();

    $exit = Artisan::call('records:ask', ['question' => 'How many potholes were filled?']);

    expect($exit)->toBe(1)
        ->and(trim(Artisan::output()))->toBe('CLAUDE_EFFORT must be one of: low, medium, high, xhigh, max.')
        ->and($transport->requests())->toBe([]);
});

it('reports an API error, the proposals saved before it and the usage, without a stack trace', function () {
    claude('pavilion_get', 'pavilion_propose')->queueError(529, ['type' => 'overloaded_error', 'message' => 'Fixture overload.']);

    $exit = Artisan::call('records:ask', ['question' => 'Please release the Willowbend Park pavilion booking.']);
    $output = Artisan::output();
    $pending = PendingRelease::query()->sole();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No answer: the Claude API returned an error')
        ->toContain('The Claude API request failed (InternalServerException, HTTP 529, request id none).')
        ->toContain("{$pending->id} for REC-0004")
        ->toContain('2 model call(s)')
        ->not->toContain('Fixture overload')
        ->not->toContain('#0 ');
});
