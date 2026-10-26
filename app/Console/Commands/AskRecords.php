<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Assistant\AssistantResult;
use App\Assistant\ToolUseLoop;
use App\Assistant\UntrustedText;
use App\Console\ConsoleText;
use App\Console\ReviewerInput;
use App\Enums\AnswerStatus;
use App\Models\PendingRelease;
use Illuminate\Console\Command;

final class AskRecords extends Command
{
    protected $signature = 'records:ask {question : Your question about the town records, in quotes}';

    protected $description = 'Ask Claude a question about the synthetic town records';

    public function handle(ToolUseLoop $loop): int
    {
        $question = $this->argument('question');

        $errors = ReviewerInput::errors(['question' => $question], [
            'question' => ['required', 'string', 'min:3', 'max:'.UntrustedText::MAX_QUESTION_LENGTH],
        ]);

        if ($errors !== [] || ! is_string($question)) {
            $this->error(implode(' ', $errors));

            return self::INVALID;
        }

        $result = $loop->ask($question);

        $this->render($result);

        return $result->status === AnswerStatus::Verified ? self::SUCCESS : self::FAILURE;
    }

    private function render(AssistantResult $result): void
    {
        $result->status === AnswerStatus::Verified
            ? $this->info($result->status->label())
            : $this->error($result->status->label());

        if ($result->detail !== null) {
            $this->line($result->detail);
        }

        if ($result->answer !== null) {
            $this->newLine();
            $this->line(ConsoleText::safe($result->answer));
            $this->newLine();
            $this->line($result->citedRecordIds === []
                ? 'No records cited.'
                : 'Cited records: '.ConsoleText::safe(implode(', ', $result->citedRecordIds)));
        }

        foreach ($result->citationProblems as $problem) {
            $this->warn('  - '.ConsoleText::safe($problem));
        }

        $this->renderPendingReleases($result->pendingReleaseIds);

        $usage = $result->usage;
        $this->newLine();
        $this->line(sprintf(
            'Run %s: %d model call(s); tokens: %s input, %s output, %s cache write, %s cache read; estimated cost: %s',
            $result->runId,
            $result->iterations,
            number_format($usage->inputTokens),
            number_format($usage->outputTokens),
            number_format($usage->cacheWriteTokens),
            number_format($usage->cacheReadTokens),
            $usage->estimatedCostUsd === null ? 'unknown (no price configured for a model used)' : '$'.number_format($usage->estimatedCostUsd, 4),
        ));
    }

    /**
     * Read from the database, not from the model's answer, so the user sees
     * what was actually saved whatever the answer claims.
     *
     * @param  list<string>  $ids
     */
    private function renderPendingReleases(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->newLine();
        $this->warn('Release proposals saved. Nothing has been released; a person must approve each one:');

        foreach (PendingRelease::query()->whereKey($ids)->get() as $pending) {
            $this->line("  {$pending->id} for {$pending->record_id}: php artisan records:approve {$pending->id} --reviewer=\"Your Name\"");
        }
    }
}
