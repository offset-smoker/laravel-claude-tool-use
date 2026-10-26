<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RejectRelease;
use App\Actions\ReleaseNotAllowed;
use App\Console\ConsoleText;
use App\Console\ReviewerInput;
use Illuminate\Console\Command;

final class RejectPendingRelease extends Command
{
    protected $signature = 'records:reject
        {proposal : The proposal id, from records:pending}
        {--reviewer= : Your name, recorded as the reviewer}
        {--reason= : Why the proposal is rejected}';

    protected $description = 'Reject a release proposal; the record is not released';

    public function handle(RejectRelease $reject): int
    {
        $input = ['reviewer' => $this->option('reviewer'), 'reason' => $this->option('reason')];
        $errors = ReviewerInput::errors($input, [
            ...ReviewerInput::reviewerRule(),
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($errors !== []) {
            $this->error(implode(' ', $errors));

            return self::INVALID;
        }

        /** @var string $reviewer */
        $reviewer = $input['reviewer'];
        /** @var string $reason */
        $reason = $input['reason'];
        /** @var string $proposal */
        $proposal = $this->argument('proposal');

        try {
            $rejected = $reject($proposal, $reviewer, $reason);
        } catch (ReleaseNotAllowed $notAllowed) {
            $this->error($notAllowed->getMessage());

            return self::FAILURE;
        }

        $this->info("Rejected proposal {$rejected->id} for {$rejected->record_id}. Nothing was released. Reviewer: ".ConsoleText::safe($reviewer).'.');

        return self::SUCCESS;
    }
}
