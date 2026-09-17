<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Carrier\Models\NawrisWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NawrisWebhookEvent>
 */
class NawrisWebhookEventFactory extends Factory
{
    protected $model = NawrisWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payload' => ['to_status_code' => 7],
            'fingerprint' => hash('sha256', (string) $this->faker->unique()->numberBetween(1, 10_000_000)),
            'nawris_parcel_id' => null,
            'code' => null,
            'reference' => null,
            'status_code' => 7,
            'collected_amount' => null,
            'received_at' => now(),
            'processed_at' => null,
            'error' => null,
        ];
    }

    /** Matched, and the work done. */
    public function processed(): self
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }
}
