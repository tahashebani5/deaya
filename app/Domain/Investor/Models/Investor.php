<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Identity\Models\User;
use Database\Factories\InvestorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The person whose money is in our stock.
 *
 * A party of its own rather than a kind of `User`: most investors will never sign in, and the
 * users table is shaped for employees — a code burned from a sequence for every row, a required
 * unique email, a required Libyan phone. `user_id` is the optional link for the ones who do.
 *
 * **That link is also the whole of the portal's security.** There are no policy classes in this
 * application and `can:` authorises an ability with no model, so «this investor sees his own
 * rows» cannot be a permission. It is enforced by the endpoint carrying no id at all and the
 * query resolving `investors WHERE user_id = <the signed-in user>`.
 */
#[UseFactory(InvestorFactory::class)]
#[Fillable(['name', 'phone', 'notes', 'is_active'])]
class Investor extends Model implements HasAuditTrail
{
    /** @use HasFactory<InvestorFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * «I7» — reserved from the sequence before the insert, the way an order's number is.
     *
     * Assigned here rather than made fillable: a payload that could post it could collide two
     * investors on one code, and the partial unique index would then fail somebody else's write.
     */
    protected static function booted(): void
    {
        static::creating(function (self $investor): void {
            if ($investor->code === null) {
                $id = (int) DB::scalar(
                    "select nextval(pg_get_serial_sequence('investors', 'id'))"
                );

                $investor->id = $id;
                $investor->code = 'I'.$id;
            }
        });
    }

    /**
     * The login, when there is one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * His stake in each deal he is in.
     *
     * @return HasMany<InvestorDealShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(InvestorDealShare::class);
    }

    /**
     * Every movement of his money, oldest first — the one table every figure is derived from.
     *
     * @return HasMany<InvestorWalletEntry, $this>
     */
    public function walletEntries(): HasMany
    {
        return $this->hasMany(InvestorWalletEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** Whether anything of his is recorded — the line a delete is refused on. */
    public function hasMoneyRecorded(): bool
    {
        return $this->walletEntries()->exists();
    }
}
