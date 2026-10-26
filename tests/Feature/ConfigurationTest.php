<?php

declare(strict_types=1);

it('asks for the server-side refusal fallback by default', function () {
    $transport = claude('pothole_final');

    ask('How many potholes were filled?');

    expect($transport->body(0)['fallbacks'])->toBe('default')
        ->and($transport->requests()[0]->getHeaderLine('anthropic-beta'))->toBe('server-side-fallback-2026-07-01');
});

it('sends no fallback parameter or beta header when fallbacks are off', function () {
    config(['claude.fallbacks' => false]);
    $transport = claude('pothole_final');

    ask('How many potholes were filled?');

    expect($transport->body(0))->not->toHaveKey('fallbacks')
        ->and($transport->requests()[0]->getHeaderLine('anthropic-beta'))->toBe('');
});

it('uses the configured model and effort', function () {
    config(['claude.model' => 'claude-sonnet-5-5', 'claude.effort' => 'high']);
    $transport = claude('pothole_final');

    ask('How many potholes were filled?');

    expect($transport->body(0)['model'])->toBe('claude-sonnet-5-5')
        ->and($transport->body(0)['output_config']['effort'])->toBe('high');
});

it('sends the API key only in the authorization header', function () {
    $transport = claude('pothole_final');

    ask('How many potholes were filled?');

    $request = $transport->requests()[0];

    expect($request->getHeaderLine('authorization'))->toBe('Bearer test-key-not-real')
        ->and((string) $request->getBody())->not->toContain('test-key-not-real')
        ->and((string) $request->getUri())->not->toContain('test-key-not-real');
});
