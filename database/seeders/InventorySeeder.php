<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inventory\Enums\WarehouseType;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * The three places the business actually holds stock.
 *
 * Warehouses only — **no balances and no movements**. A seeded quantity would be a shelf count
 * with no ledger entry behind it, which is the one state this context is built to make
 * impossible; a fresh install should start at zero everywhere and get its first number from a
 * recorded arrival.
 *
 * Idempotent: safe to re-run. Matched on name, which is what the unique index is on.
 */
class InventorySeeder extends Seeder
{
    /**
     * @var list<array{name: string, type: WarehouseType, location: string|null}>
     */
    private const WAREHOUSES = [
        ['name' => 'المخزن الرئيسي', 'type' => WarehouseType::Main, 'location' => 'طرابلس'],
        ['name' => 'مخزن التشغيل', 'type' => WarehouseType::Operational, 'location' => 'طرابلس'],
    ];

    public function run(): void
    {
        foreach (self::WAREHOUSES as $warehouse) {
            Warehouse::query()->firstOrCreate(
                ['name' => $warehouse['name']],
                ['type' => $warehouse['type'], 'location' => $warehouse['location']],
            );
        }
    }
}
