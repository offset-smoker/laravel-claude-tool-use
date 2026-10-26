<?php

declare(strict_types=1);

use Anthropic\Beta\Messages\BetaUsage;
use App\Assistant\CostEstimator;

function opusPricing(): CostEstimator
{
    return new CostEstimator(['claude-opus-5-5' => ['input' => 4.0, 'output' => 20.0, 'cache_write' => 5.0, 'cache_read' => 0.2]]);
}

it('prices input, output, cache writes and cache reads per million tokens', function () {
    $usage = (new BetaUsage)
        ->withInputTokens(1_000_000)
        ->withOutputTokens(100_000)
        ->withCacheCreationInputTokens(200_000)
        ->withCacheReadInputTokens(500_000);

    // 4.00 + 2.00 + 1.00 + 0.10
    expect(opusPricing()->estimate('claude-opus-5-5', $usage))->toEqualWithDelta(7.10, 1e-9);
});

it('treats missing cache counts as zero', function () {
    expect(opusPricing()->estimate('claude-opus-5-5', (new BetaUsage)->withInputTokens(1000)->withOutputTokens(0)))
        ->toEqualWithDelta(0.004, 1e-12);
});

it('returns null for a model without a price', function () {
    expect(opusPricing()->estimate('claude-haiku-5-5', (new BetaUsage)->withInputTokens(1)->withOutputTokens(1)))->toBeNull();
});
