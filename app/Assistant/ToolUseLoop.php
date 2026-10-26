<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaAssistantMessageParam;
use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaOutputConfig;
use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolResultBlockParam;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Beta\Messages\BetaUserMessageParam;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Assistant\Tools\ToolRegistry;
use App\Enums\AnswerStatus;

/**
 * A manual tool-use loop over the Messages API.
 *
 * Each iteration is one model call. While the model stops with tool_use, every
 * tool_use block in the response is run and all of the results go back in a
 * single user message, failures as is_error results. The loop ends on any
 * other stop reason, on a failed API call, or when the iteration cap is
 * reached. Every ending is recorded as a run in the usage log.
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
        private Effort $effort,
        private int $maxTokens,
        private int $maxIterations,
        private bool $fallbacks,
    ) {}

    public function ask(string $question): AssistantResult
    {
        $context = RunContext::start();
        $messages = [BetaUserMessageParam::with(UntrustedText::question($question))];
        $outputConfig = BetaOutputConfig::with(effort: $this->effort, format: BetaJSONOutputFormat::with(FinalAnswer::schema()));
        $total = new TokenUsage;

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            try {
                $response = $this->client->beta->messages->create(
                    maxTokens: $this->maxTokens,
                    messages: $messages,
                    model: $this->model,
                    fallbacks: $this->fallbacks ? 'default' : null,
                    outputConfig: $outputConfig,
                    system: SystemPrompt::blocks(),
                    tools: $this->tools->definitions(),
                    betas: $this->fallbacks ? [self::FALLBACK_BETA] : null,
                );
            } catch (APIException $exception) {
                return $this->result(
                    $context,
                    AnswerStatus::ApiError,
                    $iteration - 1,
                    $total,
                    detail: self::describe($exception),
                );
            }

            $total = $total->plus($this->usage->recordCall($context->runId, $iteration, $response));

            if ($response->stopReason !== 'tool_use') {
                return $this->finish($response, $context, $iteration, $total);
            }

            $toolResults = $this->runTools($response, $context);

            if ($toolResults === []) {
                return $this->result(
                    $context,
                    AnswerStatus::UnexpectedStop,
                    $iteration,
                    $total,
                    detail: 'The response stopped for tool_use but contained no tool_use block.',
                );
            }

            $messages[] = $this->replay($response);
            $messages[] = BetaUserMessageParam::with($toolResults);
        }

        return $this->result(
            $context,
            AnswerStatus::IterationLimit,
            $this->maxIterations,
            $total,
            detail: "Stopped after {$this->maxIterations} model calls without a final answer.",
        );
    }

    /**
     * The assistant turn goes back whole and unchanged: thinking blocks with
     * their signatures, any fallback block, text and every tool_use.
     */
    private function replay(BetaMessage $response): BetaAssistantMessageParam
    {
        $wire = $response->jsonSerialize();

        return BetaAssistantMessageParam::fromArray([
            'role' => 'assistant',
            'content' => is_array($wire) ? $wire['content'] : [],
        ]);
    }

    /**
     * The exception class, HTTP status and request id only. The SDK's message
     * embeds the response body, which is not printed or logged.
     */
    private static function describe(APIException $exception): string
    {
        return sprintf(
            'The Claude API request failed (%s, %s, request id %s).',
            class_basename($exception),
            $exception->status === null ? 'no HTTP status' : "HTTP {$exception->status}",
            $exception->getRequestID() ?? 'none',
        );
    }

    /** @return list<BetaToolResultBlockParam> */
    private function runTools(BetaMessage $response, RunContext $context): array
    {
        $results = [];

        foreach ($response->content as $block) {
            if (! $block instanceof BetaToolUseBlock) {
                continue;
            }

            $result = $this->tools->run($block->name, $block->input, $context);

            $results[] = BetaToolResultBlockParam::with(
                toolUseID: $block->id,
                content: $result->content,
                isError: $result->isError,
            );
        }

        return $results;
    }

    private function finish(BetaMessage $response, RunContext $context, int $iterations, TokenUsage $total): AssistantResult
    {
        if ($response->stopReason === 'refusal') {
            return $this->result(
                $context,
                AnswerStatus::Refused,
                $iterations,
                $total,
                detail: 'The model declined'.($response->stopDetails?->category !== null ? " (category: {$response->stopDetails->category})" : '').'.',
            );
        }

        if ($response->stopReason === 'max_tokens') {
            return $this->result(
                $context,
                AnswerStatus::Truncated,
                $iterations,
                $total,
                detail: 'The response hit max_tokens. No tool call in it was run.',
            );
        }

        if ($response->stopReason !== 'end_turn') {
            return $this->result(
                $context,
                AnswerStatus::UnexpectedStop,
                $iterations,
                $total,
                detail: 'Unexpected stop reason: '.($response->stopReason ?? 'none').'.',
            );
        }

        $text = '';
        foreach ($response->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        $answer = FinalAnswer::parse($text);

        if ($answer === null) {
            return $this->result(
                $context,
                AnswerStatus::Malformed,
                $iterations,
                $total,
                detail: 'The final message was not the JSON the output schema requires.',
            );
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
