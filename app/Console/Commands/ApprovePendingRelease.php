<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApproveRelease;
use App\Actions\Redactor;
use App\Actions\ReleaseNotAllowed;
use App\Console\ConsoleText;
use App\Console\ReviewerInput;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use Illuminate\Console\Command;

final class ApprovePendingRelease extends Command
{
    protected $signature = 'records:approve
        {proposal : The proposal id, from records:pending}
        {--reviewer= : Your name, recorded as the approver}
        {--note= : An optional note kept with the approval}';

    protected $description = 'Review a release proposal and, if you confirm, release the redacted record';

    public function handle(ApproveRelease $approve, Redactor $redactor): int
    {
        $input = ['reviewer' => $this->option('reviewer'), 'note' => $this->option('note')];
        $errors = ReviewerInput::errors($input, [
            ...ReviewerInput::reviewerRule(),
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($errors !== []) {
            $this->error(implode(' ', $errors));

            return self::INVALID;
        }

        /** @var string $reviewer */
        $reviewer = $input['reviewer'];
        /** @var string|null $note */
        $note = $input['note'];

        $pending = PendingRelease::query()->with('record')->find($this->argument('proposal'));

        if (! $pending instanceof PendingRelease) {
            $this->error('No proposal has that id. Run php artisan records:pending to list them.');

            return self::FAILURE;
        }

        if ($pending->status !== ReleaseStatus::Pending) {
            $this->error("Proposal {$pending->id} is already {$pending->status->value}.");

            return self::FAILURE;
        }

        $this->line("Proposal {$pending->id}: release {$pending->record_id}, \"".ConsoleText::safe($pending->record->title).'"');
        $this->line('Rationale given by the model: '.ConsoleText::safe($pending->rationale));
        $this->newLine();

        if ($pending->redactions === []) {
            $this->warn('This proposal redacts nothing. The full record text would be released.');
        }

        foreach ($pending->redactions as $redaction) {
            $this->line('  Redact ('.ConsoleText::safe($redaction['reason']).'): "'.ConsoleText::safe($redaction['text']).'"');
        }

        $this->newLine();
        $this->line('Text that would be released:');
        $this->line(ConsoleText::safe($redactor->apply($pending->record->body, $pending->redactions)));
        $this->newLine();

        $count = count($pending->redactions);
        if (! $this->confirm("Release {$pending->record_id} with {$count} redaction(s), approved by {$reviewer}? This cannot be undone.")) {
            $this->line('Nothing was released.');

            return self::FAILURE;
        }

        try {
            $approved = $approve($pending->id, $reviewer, $note);
        } catch (ReleaseNotAllowed $notAllowed) {
            $this->error($notAllowed->getMessage());

            return self::FAILURE;
        }

        $this->info("Released {$approved->record_id} (proposal {$approved->id}), approved by ".ConsoleText::safe($reviewer).'.');

        return self::SUCCESS;
    }
}
