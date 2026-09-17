<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderDesignStatus;
use Database\Factories\OrderDesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One version of an order's artwork — a row in the conversation, not a state of the order.
 *
 * Points at the customer's library rather than holding a file of its own. That is safe because
 * {@see CustomerDesign} never deletes a file and a different file is a different row by force of
 * its checksum index, so the reference cannot dangle and cannot silently change underneath a
 * finished order.
 *
 * `version` and `status` are not fillable: the version is allocated by the action that adds one,
 * and the status moves only through review, which has a reason to enforce.
 */
#[UseFactory(OrderDesignFactory::class)]
#[Fillable(['customer_design_id', 'notes'])]
class OrderDesign extends Model
{
    /** @use HasFactory<OrderDesignFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderDesignStatus::class,
            'version' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<CustomerDesign, $this>
     */
    public function customerDesign(): BelongsTo
    {
        return $this->belongsTo(CustomerDesign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
