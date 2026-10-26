<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use Illuminate\Support\Facades\DB;

/**
 * The only code path that releases a record. It runs from the
 * records:approve command, which a person types; the tool loop cannot reach it.
 */
final readonly class ApproveRelease
{
    public function __construct(
        private Redactor $redactor,
    ) {}

    /** @throws ReleaseNotAllowed */
    public function __invoke(string $pendingReleaseId, string $reviewer, ?string $note): PendingRelease
    {
        return DB::transaction(function () use ($pendingReleaseId, $reviewer, $note): PendingRelease {
            $pending = PendingRelease::query()->with('record')->lockForUpdate()->find($pendingReleaseId);

            if (! $pending instanceof PendingRelease) {
                throw new ReleaseNotAllowed("No proposal has the id {$pendingReleaseId}.");
            }

            if ($pending->status !== ReleaseStatus::Pending) {
                throw new ReleaseNotAllowed("Proposal {$pending->id} is already {$pending->status->value}.");
            }

            if ($pending->record->bodyHash() !== $pending->record_body_sha256) {
                throw new ReleaseNotAllowed("{$pending->record_id} has changed since this proposal was made. Reject it and ask for a new one.");
            }

            $releasedBody = $this->redactor->apply($pending->record->body, $pending->redactions);

            // Conditional on status, so two reviewers approving at once release once.
            $updated = PendingRelease::query()
                ->whereKey($pending->id)
                ->where('status', ReleaseStatus::Pending)
                ->update([
                    'status' => ReleaseStatus::Approved,
                    'reviewed_by' => $reviewer,
                    'review_note' => $note,
                    'reviewed_at' => now(),
                    'released_body' => $releasedBody,
                ]);

            if ($updated !== 1) {
                throw new ReleaseNotAllowed("Proposal {$pending->id} was reviewed by someone else first.");
            }

            return $pending->refresh();
        });
    }
}
