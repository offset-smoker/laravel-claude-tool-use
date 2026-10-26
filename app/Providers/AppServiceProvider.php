<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Assistant\CitationVerifier;
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
        // With no key configured the SDK resolves credentials itself
        // (ANTHROPIC_AUTH_TOKEN, an `ant auth login` profile, and so on).
        $this->app->singleton(Client::class, static function (): Client {
            $key = config('claude.api_key');

            return is_string($key) && $key !== '' ? new Client(apiKey: $key) : new Client;
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
            $fallbacks = config()->string('claude.fallbacks');

            return new ToolUseLoop(
                client: $app->make(Client::class),
                tools: $app->make(ToolRegistry::class),
                usage: $app->make(UsageRecorder::class),
                citations: $app->make(CitationVerifier::class),
                model: config()->string('claude.model'),
                effort: config()->string('claude.effort'),
                maxTokens: config()->integer('claude.max_tokens'),
                maxIterations: max(1, config()->integer('claude.max_iterations')),
                fallbacks: $fallbacks === 'off' || $fallbacks === '' ? null : $fallbacks,
            );
        });
    }
}
