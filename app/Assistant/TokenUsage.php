<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaUsage;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $cacheReadTokens = 0,
        public ?float $estimatedCostUsd = 0.0,
    ) {}

    public static function fromResponse(BetaUsage $usage, ?float $estimatedCostUsd): self
    {
        return new self(
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheCreationInputTokens ?? 0,
            $usage->cacheReadInputTokens ?? 0,
            $estimatedCostUsd,
        );
    }

    /** A total with an unknown-price call in it has an unknown cost. */
    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->estimatedCostUsd === null || $other->estimatedCostUsd === null
                ? null
                : $this->estimatedCostUsd + $other->estimatedCostUsd,
        );
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int, estimated_cost_usd: float|null} */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'estimated_cost_usd' => $this->estimatedCostUsd === null ? null : round($this->estimatedCostUsd, 6),
        ];
    }
}
