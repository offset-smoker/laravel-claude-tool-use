<?php

declare(strict_types=1);

use Anthropic\Client;
use App\Assistant\ClaudeNotConfigured;
use Illuminate\Support\Facades\File;

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

it('refuses to build a client, and so sends nothing, when no credentials exist anywhere', function () {
    $emptyConfigDir = sys_get_temp_dir().'/claude-tool-use-no-profile-'.getmypid();
    File::ensureDirectoryExists($emptyConfigDir);

    // Every variable the SDK's credential chain reads. $_ENV wins over the
    // process environment, so blank values here hide a developer's own.
    $credentialVariables = [
        'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_PROFILE', 'ANTHROPIC_CONFIG_DIR',
        'ANTHROPIC_FEDERATION_RULE_ID', 'ANTHROPIC_ORGANIZATION_ID', 'ANTHROPIC_SERVICE_ACCOUNT_ID',
        'ANTHROPIC_WORKSPACE_ID', 'ANTHROPIC_IDENTITY_TOKEN', 'ANTHROPIC_IDENTITY_TOKEN_FILE',
    ];
    $environmentBefore = $_ENV;
    $saved = array_intersect_key($_ENV, array_flip($credentialVariables));

    foreach ($credentialVariables as $variable) {
        $_ENV[$variable] = '';
    }
    $_ENV['ANTHROPIC_CONFIG_DIR'] = $emptyConfigDir;
    config(['claude.api_key' => null]);
    app()->forgetInstance(Client::class);

    try {
        expect(fn () => app(Client::class))->toThrow(ClaudeNotConfigured::class, 'No Claude credentials found.');
    } finally {
        foreach ($credentialVariables as $variable) {
            unset($_ENV[$variable]);
        }
        $_ENV = [...$_ENV, ...$saved];
        File::deleteDirectory($emptyConfigDir);
    }

    // Nothing leaks into later tests.
    expect($_ENV)->toEqual($environmentBefore);
});

it('builds a client from the configured key', function () {
    config(['claude.api_key' => 'sk-ant-configured-in-test']);
    app()->forgetInstance(Client::class);

    expect(app(Client::class)->apiKey)->toBe('sk-ant-configured-in-test');
});

it('keeps function arguments, such as the question, out of exception traces', function () {
    $trace = (fn (string $question): string => (new RuntimeException('x'))->getTraceAsString())('Who asked for the pothole repair?');

    expect(ini_get('zend.exception_ignore_args'))->toBe('1')
        ->and($trace)->not->toContain('Who asked');
});
