<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A thing that happened, worth telling somebody about.
 *
 * **Neither `Auditable` nor `SoftDeletes`, and that is a decision rather than an oversight** —
 * stated here because RULES.md §10 makes both universal and a reader is right to check. This is
 * an event record, the same category as `ActivityLog` and `NawrisWebhookEvent`, which carry the
 * identical exemption: auditing it would record that a record arrived, and soft deleting it
 * would defeat the retention prune that is the only thing keeping the table bounded.
 * `ModelConventionsTest` names all three.
 *
 * **Nothing here is fillable.** Every column is server-assigned — the type, the payload, the
 * causer — and no request supplies any of it, so the model keeps Eloquent's default guard and
 * the actions assign properties directly. With `Model::shouldBeStrict()` on outside production,
 * an accidental mass assignment throws rather than being silently dropped.
 *
 * @property int $id
 * @property NotificationType $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $payload
 * @property string|null $dedupe_key
 * @property int|null $causer_id
 * @property Carbon $created_at
 */
class Notification extends Model
{
    /**
     * A notification is immutable: it describes a moment that has already passed. The only fact
     * that ever changes is per-person and lives on {@see NotificationRecipient::$read_at}.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Everyone this reached. Empty is ordinary, not an error: a definition whose audience holds
     * nobody — or only the person who caused it — writes this row and no recipients at all.
     *
     * @return HasMany<NotificationRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }

    /**
     * The person whose action produced this, so they can be left out of hearing about it.
     *
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * What it is about — an order, a stock item, or nothing at all for an announcement.
     *
     * Resolved through the application's morph map, so `subject_type` holds `order` rather than
     * a class name. Rarely loaded: the payload already carries everything the sentence needs,
     * which is what keeps the list free of joins.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
