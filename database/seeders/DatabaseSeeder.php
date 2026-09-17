<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * **Model events stay on.** The scaffold shipped this class with `WithoutModelEvents`, and
     * that was fine until identifiers moved onto the models: `Product::booted()` allocates
     * `code` (NOT NULL) and derives `slug`, and `User::booted()` allocates `employee_code`.
     * Muting events took both away — the catalogue could not be seeded onto an empty database
     * at all, and admin accounts were written with no employee code, silently, because that
     * column tolerates a null. The events are how those columns get filled; a seeder is not a
     * special case that gets to skip them.
     */
    public function run(): void
    {
        $this->call([
            // Roles first — the accounts below are assigned roles as they are created.
            RoleSeeder::class,
            AdminSeeder::class,
            // Before the catalogue, because that seeder files each product under a heading as
            // it creates it — «مطبوعة» or «سادة», the two that replaced the old «النوع» column.
            ProductCategorySeeder::class,
            CatalogSeeder::class,
            DeliveryLocationSeeder::class,
            BusinessFieldSeeder::class,
            // Warehouses only. Stock arrives by being recorded, never by being seeded.
            InventorySeeder::class,
            // Last of the three that feed it: the book files every customer under a city and a
            // trade, and resolves both by name against what the two seeders above just wrote.
            CustomerSeeder::class,
        ]);
    }
}
