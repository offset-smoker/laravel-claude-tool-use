<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Department;
use Carbon\CarbonImmutable;
use Database\Factories\RecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A synthetic public record. Every record in this app is fictional.
 *
 * @property string $id
 * @property string $title
 * @property Department $department
 * @property CarbonImmutable $filed_on
 * @property string $body
 */
final class Record extends Model
{
    /** @use HasFactory<RecordFactory> */
    use HasFactory;

    public const string ID_PATTERN = '/^REC-\d{4}$/';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'title', 'department', 'filed_on', 'body'];

    /** @return HasMany<PendingRelease, $this> */
    public function pendingReleases(): HasMany
    {
        return $this->hasMany(PendingRelease::class);
    }

    public function bodyHash(): string
    {
        return hash('sha256', $this->body);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'department' => Department::class,
            'filed_on' => 'immutable_date',
        ];
    }
}
