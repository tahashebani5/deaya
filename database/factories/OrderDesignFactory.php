<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderDesign>
 */
class OrderDesignFactory extends Factory
{
    protected $model = OrderDesign::class;

    /** Versions are unique per order, so they come from a counter rather than chance. */
    private static int $versionSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'customer_design_id' => CustomerDesign::factory(),
            'version' => ++self::$versionSequence,
            'status' => OrderDesignStatus::Proposed,
            'rejection_reason' => null,
            'notes' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => OrderDesignStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'الألوان غير مطابقة'): static
    {
        return $this->state(fn () => [
            'status' => OrderDesignStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);
    }
}
