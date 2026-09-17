<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Media\StoreReceipt;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\PaymentMethod;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One entry in an order's money ledger.
 *
 * **Nothing updates one and nothing deletes one.** There is no route that does, and a correction
 * is a further entry pointing back at this one — see {@see OrderPaymentType}. A ledger you can
 * rewrite explains nothing, and being able to explain is the entire reason this table exists
 * rather than a `paid_amount` column that goes up and down.
 *
 * `amount` is always positive; the direction is the type's, so a sum over the table cannot be
 * quietly wrong because somebody stored a row negative. The database holds that as a CHECK too.
 *
 * Only the fields a human genuinely supplies are fillable. The type is decided by which action
 * ran, `recorded_by` is stamped from the signed-in user, `reverses_payment_id` is set by the
 * reversal itself, and the five `receipt_*` columns are written by
 * {@see StoreReceipt} from the file it actually stored — so no payload can invent an
 * entry type, attribute a collection to a colleague, point a correction at somebody else's row,
 * or claim a receipt exists at a path of its choosing. See RULES.md §9.4.
 */
#[UseFactory(OrderPaymentFactory::class)]
#[Fillable(['amount', 'method', 'reference', 'paid_at', 'notes'])]
class OrderPayment extends Model
{
    /** @use HasFactory<OrderPaymentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderPaymentType::class,
            'method' => PaymentMethod::class,
            // A string, not a float: this is summed into `orders.paid_amount`, and money that
            // is summed must stay exact.
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'receipt_size_bytes' => 'integer',
        ];
    }

    /**
     * Whether the paper backing this entry is on file.
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null;
    }

    /**
     * Whether the receipt is a picture the app can draw itself, as opposed to a PDF it hands
     * to the phone.
     *
     * Answered from the *stored* path, whose extension {@see StoreReceipt} derived from
     * the sniffed bytes — so a JPEG that arrived calling itself `waseel.pdf` still answers
     * true. Published on the resource so the app keeps no copy of the format list.
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
     * A link to the receipt, built on demand from the disk it actually lives on.
     *
     * **Never stored**, exactly like a customer's design: the row records the disk and the path,
     * so moving to S3 is a config change with no migration. And the disk is private — a receipt
     * carries somebody's bank details — so in production this is a signed link that expires, and
     * the capability is asked of the disk rather than assumed.
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

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The entry this one undoes. Set on a reversal and null on everything else.
     *
     * @return BelongsTo<OrderPayment, $this>
     */
    public function reversedPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    /**
     * The reversal that undid this entry, if one exists.
     *
     * `hasOne` rather than `hasMany` because a unique index says so: an entry is undone at most
     * once, and the second attempt is refused by the database rather than by a check somebody
     * could forget to write.
     *
     * @return HasOne<OrderPayment, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Whether this entry has been undone.
     *
     * Reads what was loaded before it asks the database — this is answered for every row of a
     * ledger being rendered, and a query per row is how a screen becomes slow.
     */
    public function isReversed(): bool
    {
        if ($this->relationLoaded('reversal')) {
            return $this->reversal !== null;
        }

        return $this->reversal()->exists();
    }

    /**
     * Whether this entry may be undone at all.
     *
     * **A payment or a write-off, and each only once.** Both are claims that can simply be
     * wrong — a figure mistyped at the counter, a difference forgiven on the wrong order — and
     * neither moved cash that would have to be fetched back to undo it.
     *
     * Reversing a reversal is a maze with no floor: the second one would have to mean "the
     * correction was wrong", which is the same statement as a new payment and reads far worse in
     * a ledger. Somebody who reversed by mistake records the payment again.
     *
     * A refund is money that genuinely left the drawer. Undoing it is a *payment* — the customer
     * gave it back — not a claim that it never happened.
     */
    public function isReversible(): bool
    {
        return $this->type->isCredit() && ! $this->isReversed();
    }

    /**
     * Which of the order's two totals this entry moves: what was forgiven, or what was paid.
     *
     * **A reversal answers for the row it undoes**, and this is the only place that lookup
     * happens. A reversal of a write-off must come back off `written_off_amount` — taking it off
     * `paid_amount` instead would leave an order claiming cash it never had, which is the exact
     * confusion the second column exists to prevent.
     *
     * Reads what was loaded before it asks the database, like {@see isReversed()}: the recalculate
     * pass walks a whole ledger, and a query per row is how a save becomes slow.
     */
    public function affectsWriteOff(): bool
    {
        if ($this->type->isWriteOff()) {
            return true;
        }

        if ($this->type !== OrderPaymentType::Reversal) {
            return false;
        }

        return $this->reversedPayment?->type->isWriteOff() ?? false;
    }

    /**
     * Whether this entry moves what the carrier collected at the door rather than either of the
     * other two totals.
     *
     * The exact shape of {@see affectsWriteOff()}, one total along, and it exists for the same
     * reason: **a reversal answers for the row it undoes.** A reversal of a carrier settlement
     * must come back off `carrier_settled_amount` — taking it off `paid_amount` would leave an
     * order claiming cash it never had, and off `written_off_amount` would leave it claiming a
     * loss nobody decided on.
     *
     * Reads what was loaded before it asks the database, like {@see affectsWriteOff()}.
     */
    public function affectsCarrierSettlement(): bool
    {
        if ($this->type->isCarrierSettled()) {
            return true;
        }

        if ($this->type !== OrderPaymentType::Reversal) {
            return false;
        }

        return $this->reversedPayment?->type->isCarrierSettled() ?? false;
    }

    /**
     * What this entry does to the total it belongs to, signed.
     *
     * The one place the direction of a row is turned into arithmetic, so no caller has to
     * remember which of the four types subtracts. **Which total it lands in is a separate
     * question** — see {@see affectsWriteOff()} — and keeping the two apart is what let a fourth
     * entry type arrive without a single caller re-deriving the sign rule.
     */
    public function signedAmount(): string
    {
        $amount = (string) $this->amount;

        return $this->type->isCredit() ? $amount : '-'.$amount;
    }
}
