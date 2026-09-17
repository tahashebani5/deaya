<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Shortage\Actions\RecalculateShortageTotals;
use App\Domain\Shortage\Enums\SupplyKind;
use Database\Factories\ShortageSupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Application\Api\V1\Requests\Shortage\RecordShortageSupplyRequest;
use App\Support\Media\StoreReceipt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One go at closing a shortage — what came back, what it cost, and how it was paid for.
 *
 * **Append-only.** No route updates one and none deletes one; a correction is a reversing row
 * that names the row it undoes, and {@see RecalculateShortageTotals} restates the two caches on
 * `shortages` afterwards. That is what makes «منع تكرار الخصم المالي» a property of the schema
 * rather than a rule somebody has to keep in mind — the same bargain `OrderPayment` strikes.
 *
 * Nothing here is fillable but the fields a person actually types. `kind`, `shortage_id`,
 * `reverses_supply_id` and `recorded_by_user_id` are all stamped by the Action, because a payload
 * that could set them could attribute somebody else's purchase, or write a reversal that undoes
 * nothing.
 */
#[UseFactory(ShortageSupplyFactory::class)]
#[Fillable(['quantity', 'amount', 'method', 'reference', 'occurred_on', 'notes'])]
class ShortageSupply extends Model
{
    /** @use HasFactory<ShortageSupplyFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SupplyKind::class,
            'method' => PaymentMethod::class,
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'receipt_size_bytes' => 'integer',
        ];
    }

    /**
     * Whether the paper this purchase was made with is on file.
     *
     * **Often false, and that is not a gap.** A sack bought from the shop next door frequently
     * comes with nothing; the entry is worth having either way — see
     * {@see RecordShortageSupplyRequest}.
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null;
    }

    /**
     * Whether it is a picture the app can draw itself, as opposed to a PDF it hands to the phone.
     *
     * Read from the *stored* path, whose extension {@see StoreReceipt} derived from the sniffed
     * bytes — so a JPEG that arrived calling itself `waseel.pdf` still answers true.
     */
    public function receiptIsImage(): bool
    {
        if (! $this->hasReceipt()) {
            return false;
        }

        $extension = strtolower(pathinfo((string) $this->receipt_path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * A link to it, built on demand from the disk it actually lives on — never stored, exactly
     * as `OrderPayment::receiptUrl()` explains.
     */
    public function receiptUrl(): ?string
    {
        if (! $this->hasReceipt()) {
            return null;
        }

        $disk = Storage::disk($this->receipt_disk);

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($this->receipt_path, now()->addMinutes(config('media.temporary_url_minutes')))
            : $disk->url($this->receipt_path);
    }

    /** Whether this row undoes another one. */
    public function isReversal(): bool
    {
        return $this->reverses_supply_id !== null;
    }

    /**
     * Whether a live reversal already stands against this row.
     *
     * Asked under the lock before a second one is written — the unique index is the guarantee,
     * this is what turns it into a readable 422 instead of a 500. The `OrderPayment::isReversed()`
     * shape.
     */
    public function isReversed(): bool
    {
        return $this->reversedBy()->exists();
    }

    /**
     * Whether this entry put stock on a shelf.
     *
     * The pair is all-or-nothing in the schema — see the migration's `arrival_shape` CHECK — so
     * either column answers, and reading the movement is the one that matters to a reversal.
     */
    public function movedStock(): bool
    {
        return $this->stock_movement_id !== null;
    }

    /**
     * The shelf the goods landed on, when they did.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The arrival this purchase posted — the anchor a reversal withdraws from.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * @return BelongsTo<Shortage, $this>
     */
    public function shortage(): BelongsTo
    {
        return $this->belongsTo(Shortage::class);
    }

    /**
     * The row this one undoes, when it undoes one.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversedSupply(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_supply_id');
    }

    /**
     * The reversal standing against this row, if one does.
     *
     * `hasOne` rather than `hasMany` because the partial unique index allows exactly one — the
     * relation states the same rule the database enforces, so a reader meets it in both places.
     *
     * @return HasOne<self, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_supply_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
