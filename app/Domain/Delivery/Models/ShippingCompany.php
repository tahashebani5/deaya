<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Delivery\Actions\UpdateShippingCompany;
use Database\Factories\ShippingCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company that carries our parcels.
 *
 * A record rather than a name typed onto each order: one company, one spelling, one phone
 * number, and a way to stop offering it without erasing the orders it already carried.
 *
 * **Orders keep a snapshot of the name beside the key**, exactly as they do for the city. What
 * an order says carried it is a fact about that day, and renaming a row here must not rewrite it.
 *
 * **`is_default` is the one the dispatch form opens on**, and at most one live company may hold
 * it — a partial unique index says so, and {@see UpdateShippingCompany} moves it rather than
 * duplicating it. It cannot sit on a company we no longer deal with: a carrier that is not
 * offered at all is not the answer to «من سيأخذها».
 */
#[UseFactory(ShippingCompanyFactory::class)]
#[Fillable(['name', 'phone', 'notes', 'is_active', 'is_default'])]
class ShippingCompany extends Model implements HasAuditTrail
{
    /** @use HasFactory<ShippingCompanyFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
