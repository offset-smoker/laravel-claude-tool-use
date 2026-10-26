<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ConsoleText;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use Illuminate\Console\Command;

final class ListPendingReleases extends Command
{
    protected $signature = 'records:pending';

    protected $description = 'List release proposals waiting for a reviewer';

    public function handle(): int
    {
        $pending = PendingRelease::query()
            ->with('record')
            ->where('status', ReleaseStatus::Pending)
            ->oldest()
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No releases are waiting for review.');

            return self::SUCCESS;
        }

        $this->table(
            ['Proposal', 'Record', 'Title', 'Redactions', 'Proposed'],
            $pending->map(fn (PendingRelease $release): array => [
                $release->id,
                $release->record_id,
                ConsoleText::safe($release->record->title),
                count($release->redactions),
                $release->created_at->toDateTimeString(),
            ])->all(),
        );

        $this->line('Review one with: php artisan records:approve <proposal> --reviewer="Your Name"');
        $this->line('or: php artisan records:reject <proposal> --reviewer="Your Name" --reason="Why"');

        return self::SUCCESS;
    }
}
