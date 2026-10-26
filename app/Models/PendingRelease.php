<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A release the model proposed. It stays pending until a person approves or
 * rejects it with an artisan command; nothing in the tool loop can do either.
 *
 * status, reviewed_by, review_note, reviewed_at and released_body are not
 * fillable: only ApproveRelease and RejectRelease write them.
 *
 * @property string $id
 * @property string $record_id
 * @property list<array{text: string, reason: string}> $redactions
 * @property string $rationale
 * @property string $record_body_sha256
 * @property string $requested_in_run
 * @property ReleaseStatus $status
 * @property string|null $reviewed_by
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $released_body
 * @property CarbonImmutable $created_at
 * @property-read Record $record
 */
final class PendingRelease extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['record_id', 'redactions', 'rationale', 'record_body_sha256', 'requested_in_run'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return BelongsTo<Record, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'redactions' => 'array',
            'status' => ReleaseStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
