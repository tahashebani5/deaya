<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use Database\Factories\BusinessFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مجال العمل — the trade a customer's shop is in.
 *
 * Reference data the business curates: a short list, edited from a screen, pointed at by
 * {@see CustomerShop}. It is stored so that the question «من نبيع له؟» can be answered from
 * records rather than from memory once enough shops carry one.
 *
 * A {@see HasAuditTrail} — unlike a shop, this *has* a screen of its own, so «من غيّر اسم هذا
 * المجال؟» is a question somebody will ask in front of it.
 */
#[UseFactory(BusinessFieldFactory::class)]
#[Fillable(['name', 'is_active', 'sort_order'])]
class BusinessField extends Model implements HasAuditTrail
{
    /** @use HasFactory<BusinessFieldFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The shops recorded as being in this trade.
     *
     * Its only real job is answering "is anything using this?" before a delete — and, later,
     * the reports this table exists for.
     *
     * @return HasMany<CustomerShop, $this>
     */
    public function shops(): HasMany
    {
        return $this->hasMany(CustomerShop::class);
    }

    /**
     * Whether a shop anywhere points at this field.
     *
     * Counts soft-deleted shops too, deliberately: a deleted shop can be restored, and a field
     * deleted in the meantime would leave it pointing at nothing.
     */
    public function isInUse(): bool
    {
        return $this->shops()->withTrashed()->exists();
    }
}
