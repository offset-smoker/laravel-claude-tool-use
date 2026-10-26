<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use App\Assistant\Tools\ToolRegistry;
use App\Enums\AnswerStatus;

/**
 * A manual tool-use loop over the Messages API.
 *
 * Each iteration is one model call. While the model stops with tool_use, every
 * tool_use block in the response is run and all of the results go back in a
 * single user message, failures as is_error results. The loop ends on any
 * other stop reason or when the iteration cap is reached.
 */
final readonly class ToolUseLoop
{
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private Client $client,
        private ToolRegistry $tools,
        private UsageRecorder $usage,
        private CitationVerifier $citations,
        private string $model,
        private string $effort,
        private int $maxTokens,
        private int $maxIterations,
        private ?string $fallbacks,
    ) {}

    public function ask(string $question): AssistantResult
    {
        $context = RunContext::start();
        $messages = [['role' => 'user', 'content' => UntrustedText::question($question)]];
        $total = new TokenUsage;

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $response = $this->client->beta->messages->create(
                maxTokens: $this->maxTokens,
                messages: $messages,
                model: $this->model,
                fallbacks: $this->fallbacks,
                outputConfig: ['effort' => $this->effort, 'format' => FinalAnswer::format()],
                system: SystemPrompt::blocks(),
                tools: $this->tools->definitions(),
                betas: $this->fallbacks === null ? null : [self::FALLBACK_BETA],
            );

            $total = $total->plus($this->usage->recordCall($context->runId, $iteration, $response));

            if ($response->stopReason !== 'tool_use') {
                return $this->finish($response, $context, $iteration, $total);
            }

            // The whole content goes back, thinking blocks included, unchanged.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $this->runTools($response, $context)];
        }

        return $this->result($context, AnswerStatus::IterationLimit, $this->maxIterations, $total,
            detail: "Stopped after {$this->maxIterations} model calls without a final answer.");
    }

    /** @return list<array{type: 'tool_result', toolUseID: string, content: string, isError: bool}> */
    private function runTools(BetaMessage $response, RunContext $context): array
    {
        $results = [];

        foreach ($response->content as $block) {
            if (! $block instanceof BetaToolUseBlock) {
                continue;
            }

            $result = $this->tools->run($block->name, $block->input, $context);

            $results[] = [
                'type' => 'tool_result',
                'toolUseID' => $block->id,
                'content' => $result->content,
                'isError' => $result->isError,
            ];
        }

        return $results;
    }

    private function finish(BetaMessage $response, RunContext $context, int $iterations, TokenUsage $total): AssistantResult
    {
        if ($response->stopReason === 'refusal') {
            return $this->result($context, AnswerStatus::Refused, $iterations, $total,
                detail: 'The model declined'.($response->stopDetails?->category !== null ? " (category: {$response->stopDetails->category})" : '').'.');
        }

        if ($response->stopReason === 'max_tokens') {
            return $this->result($context, AnswerStatus::Truncated, $iterations, $total,
                detail: 'The response hit max_tokens. No tool call in it was run.');
        }

        if ($response->stopReason !== 'end_turn') {
            return $this->result($context, AnswerStatus::UnexpectedStop, $iterations, $total,
                detail: 'Unexpected stop reason: '.($response->stopReason ?? 'none').'.');
        }

        $text = '';
        foreach ($response->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        $answer = FinalAnswer::parse($text);

        if ($answer === null) {
            return $this->result($context, AnswerStatus::Malformed, $iterations, $total,
                detail: 'The final message was not the JSON the output schema requires.');
        }

        $problems = $this->citations->problems($answer, $context);

        return $this->result(
            $context,
            $problems === [] ? AnswerStatus::Verified : AnswerStatus::CitationsFailed,
            $iterations,
            $total,
            answer: $answer,
            problems: $problems,
        );
    }

    /** @param list<string> $problems */
    private function result(
        RunContext $context,
        AnswerStatus $status,
        int $iterations,
        TokenUsage $total,
        ?FinalAnswer $answer = null,
        array $problems = [],
        ?string $detail = null,
    ): AssistantResult {
        $this->usage->recordRun($context->runId, $status, $iterations, $total);

        return new AssistantResult(
            runId: $context->runId,
            status: $status,
            answer: $answer?->answer,
            citedRecordIds: $answer === null ? [] : $answer->citedRecordIds,
            citationProblems: $problems,
            pendingReleaseIds: $context->pendingReleaseIds(),
            iterations: $iterations,
            usage: $total,
            detail: $detail,
        );
    }
}
