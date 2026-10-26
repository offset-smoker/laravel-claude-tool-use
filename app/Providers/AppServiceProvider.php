<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Client;
use Anthropic\Lib\Credentials\DefaultCredentials;
use App\Assistant\CitationVerifier;
use App\Assistant\ClaudeNotConfigured;
use App\Assistant\CostEstimator;
use App\Assistant\Tools\ToolRegistry;
use App\Assistant\ToolUseLoop;
use App\Assistant\UsageRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stack traces in logged exceptions would otherwise carry the first
        // characters of string arguments, which here include questions and
        // record text.
        ini_set('zend.exception_ignore_args', '1');

        // Without a key, fall back to the SDK's other credential sources
        // (ANTHROPIC_AUTH_TOKEN, an `ant auth login` profile, workload
        // identity). With none of them, stop before any request is sent.
        $this->app->singleton(Client::class, static function (): Client {
            $key = config('claude.api_key');

            if (is_string($key) && $key !== '') {
                return new Client(apiKey: $key);
            }

            $credentials = DefaultCredentials::resolve() ?? throw ClaudeNotConfigured::noCredentials();

            return new Client(credentials: $credentials);
        });

        $this->app->singleton(CostEstimator::class, static function (): CostEstimator {
            /** @var array<string, array{input: float, output: float, cache_write: float, cache_read: float}> $pricing */
            $pricing = config()->array('claude.pricing');

            return new CostEstimator($pricing);
        });

        $this->app->singleton(UsageRecorder::class, static fn (Application $app): UsageRecorder => new UsageRecorder(
            Log::channel(config()->string('claude.usage_log_channel')),
            $app->make(CostEstimator::class),
        ));

        $this->app->bind(ToolUseLoop::class, static function (Application $app): ToolUseLoop {
            return new ToolUseLoop(
                client: $app->make(Client::class),
                tools: $app->make(ToolRegistry::class),
                usage: $app->make(UsageRecorder::class),
                citations: $app->make(CitationVerifier::class),
                model: config()->string('claude.model'),
                effort: Effort::from(config()->string('claude.effort')),
                maxTokens: config()->integer('claude.max_tokens'),
                maxIterations: max(1, config()->integer('claude.max_iterations')),
                fallbacks: config()->boolean('claude.fallbacks'),
            );
        });
    }
}
