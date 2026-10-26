<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ConsoleText;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use Illuminate\Console\Command;

final class ShowRelease extends Command
{
    protected $signature = 'records:release {proposal : The proposal id}';

    protected $description = 'Show a proposal\'s review status and, once approved, the released text';

    public function handle(): int
    {
        $release = PendingRelease::query()->find($this->argument('proposal'));

        if (! $release instanceof PendingRelease) {
            $this->error('No proposal has that id. Run php artisan records:pending to list open ones.');

            return self::FAILURE;
        }

        $this->line("Proposal {$release->id} for {$release->record_id}: {$release->status->value}");

        if ($release->status === ReleaseStatus::Pending) {
            $this->line('Awaiting review. Nothing has been released.');

            return self::SUCCESS;
        }

        $this->line('Reviewed by '.ConsoleText::safe((string) $release->reviewed_by).' at '.$release->reviewed_at?->toDateTimeString());

        if ($release->review_note !== null) {
            $this->line('Note: '.ConsoleText::safe($release->review_note));
        }

        if ($release->status === ReleaseStatus::Approved && $release->released_body !== null) {
            $this->newLine();
            $this->line(ConsoleText::safe($release->released_body));
        }

        return self::SUCCESS;
    }
}
