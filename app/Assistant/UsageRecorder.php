<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaMessage;
use App\Enums\AnswerStatus;
use Psr\Log\LoggerInterface;

/**
 * Logs token counts and cost estimates. It is handed the response's usage
 * and ids only, never the messages, so no prompt, record text or answer can
 * reach the log through it.
 */
final readonly class UsageRecorder
{
    public function __construct(
        private LoggerInterface $logger,
        private CostEstimator $costs,
    ) {}

    public function recordCall(string $runId, int $iteration, BetaMessage $response): TokenUsage
    {
        $usage = TokenUsage::fromResponse($response->usage, $this->costs->estimate($response->model, $response->usage));

        $this->logger->info('claude.call', [
            'run_id' => $runId,
            'iteration' => $iteration,
            'message_id' => $response->id,
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
            ...$usage->toArray(),
        ]);

        return $usage;
    }

    public function recordRun(string $runId, AnswerStatus $status, int $iterations, TokenUsage $total): void
    {
        $this->logger->info('claude.run', [
            'run_id' => $runId,
            'status' => $status->value,
            'iterations' => $iterations,
            ...$total->toArray(),
        ]);
    }
}
