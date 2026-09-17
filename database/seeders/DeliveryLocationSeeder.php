<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The delivery map — 95 cities and the 218 regions inside them.
 *
 * The list itself lives in database/seeders/data/libya_delivery_locations.php, which also records
 * how it differs from the source export.
 *
 * **Matched by name, not by id.** The source ids cannot survive splitting إستلام مكتب into two
 * branches, and the name is what the customer picks from and what a unique index already
 * guarantees — so it is the honest key. Nothing here depends on a row landing at a given id.
 *
 * **Idempotent, and it never overwrites a pin.** Re-running updates prices, flags and branches
 * from the file, but `latitude`/`longitude` are absent from every payload, so coordinates typed in
 * by an administrator survive the next seed. That is the whole reason the columns are not listed
 * here — a seeder that silently blanked the office pins would be discovered the hard way.
 *
 * **A region removed from the file is not removed from the database.** Deleting one is a business
 * decision with orders potentially pointing at it, so it stays a deliberate act, never a side
 * effect of re-seeding.
 */
class DeliveryLocationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->locations() as $definition) {
                $city = City::updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        // Absent from all but the two office rows: the file states the exception,
                        // not the rule.
                        'fulfilment_type' => $definition['fulfilment_type'] ?? FulfilmentType::Delivery->value,
                        'is_region_required' => $definition['is_region_required'],
                        'delivery_price' => $definition['delivery_price'],
                        'darb_branch' => $definition['darb_branch'],
                    ],
                );

                foreach ($definition['regions'] as $region) {
                    // Through the relation, so city_id is set by the association rather than
                    // mass-assigned — the key is not fillable on Region for exactly that reason.
                    $city->regions()->updateOrCreate(
                        ['name' => $region['name']],
                        ['code' => $region['code'], 'darb_branch' => $region['darb_branch']],
                    );
                }
            }
        });
    }

    /**
     * @return list<array{name: string, fulfilment_type?: string, is_region_required: bool,
     *     delivery_price: string|null, darb_branch: string|null,
     *     regions: list<array{name: string, code: string|null, darb_branch: string|null}>}>
     */
    private function locations(): array
    {
        return require database_path('seeders/data/libya_delivery_locations.php');
    }
}
