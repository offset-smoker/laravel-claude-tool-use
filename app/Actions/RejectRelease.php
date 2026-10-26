<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;

final readonly class RejectRelease
{
    /** @throws ReleaseNotAllowed */
    public function __invoke(string $pendingReleaseId, string $reviewer, string $reason): PendingRelease
    {
        $updated = PendingRelease::query()
            ->whereKey($pendingReleaseId)
            ->where('status', ReleaseStatus::Pending)
            ->update([
                'status' => ReleaseStatus::Rejected,
                'reviewed_by' => $reviewer,
                'review_note' => $reason,
                'reviewed_at' => now(),
            ]);

        $pending = PendingRelease::query()->find($pendingReleaseId);

        if (! $pending instanceof PendingRelease) {
            throw new ReleaseNotAllowed("No proposal has the id {$pendingReleaseId}.");
        }

        if ($updated !== 1) {
            throw new ReleaseNotAllowed("Proposal {$pending->id} is already {$pending->status->value}.");
        }

        return $pending;
    }
}
