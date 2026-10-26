<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaUsage;

final readonly class CostEstimator
{
    /** @param array<string, array{input: float, output: float, cache_write: float, cache_read: float}> $pricing  USD per million tokens */
    public function __construct(
        private array $pricing,
    ) {}

    /** Null when the model (for example a fallback model) has no configured price. */
    public function estimate(string $model, BetaUsage $usage): ?float
    {
        $price = $this->pricing[$model] ?? null;

        if ($price === null) {
            return null;
        }

        return (
            $usage->inputTokens * $price['input']
            + $usage->outputTokens * $price['output']
            + ($usage->cacheCreationInputTokens ?? 0) * $price['cache_write']
            + ($usage->cacheReadInputTokens ?? 0) * $price['cache_read']
        ) / 1_000_000;
    }
}
