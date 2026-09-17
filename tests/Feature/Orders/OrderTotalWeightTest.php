<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * «كم تزن الطلبية؟» — asked of the lines, because the order stopped answering it itself.
 *
 * `orders.weight_kg` was a number a clerk typed and nothing read; it went in the 2026_08_23
 * migration, and the weight that survived it is the one the warehouse recorded per line on the
 * way into «جاهزة». This pins the sum built back out of those lines: which of them count, when
 * there is no answer to give, and that the figure reaches the screen only where the lines do.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderTotalWeightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * A size sold by [$pricingUnit], off a shelf counted in [$stockUnit].
     *
     * The pair is the whole subject: agreeing units need no scale, differing ones do.
     */
    private function sellableSize(PricingUnit $pricingUnit, PricingUnit $stockUnit): ProductVariant
    {
        $product = Product::factory()->create(['pricing_unit' => $pricingUnit]);

        return ProductVariant::factory()->for($product)->create([
            'stock_item_id' => StockItem::factory()->unit($stockUnit)->create()->getKey(),
        ]);
    }

    /**
     * One line on [$order], for [$size], weighed at [$warehouseQuantity] if anybody has been.
     */
    private function line(
        Order $order,
        ProductVariant $size,
        string $quantity = '300',
        ?string $warehouseQuantity = null,
    ): OrderItem {
        return OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => $quantity,
            'pricing_unit' => $size->product->pricing_unit,
            'warehouse_quantity' => $warehouseQuantity,
        ]);
    }

    public function test_an_order_off_a_shelf_counted_in_pieces_has_no_weight(): void
    {
        // Arrange — bags counted as bags, all the way through. Nothing here was ever weighed.
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Piece));

        // Act
        $weight = $order->totalWeight();

        // Assert — not '0.000': the order does not weigh nothing, it has no weight to state.
        $this->assertNull($weight);
    }

    public function test_a_weighed_line_nobody_has_weighed_yet_leaves_the_order_without_a_weight(): void
    {
        // Arrange — sold by the piece, stocked by the kilo, and not yet through «جاهزة».
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300');

        // Act
        $weight = $order->totalWeight();

        // Assert — the sold quantity is a bag count. Reading it as «300 كجم» is the one
        // mistake this figure exists to avoid.
        $this->assertNull($weight);
    }

    public function test_the_weight_is_what_the_warehouse_recorded_on_the_line(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');

        // Act
        $weight = $order->totalWeight();

        // Assert
        $this->assertSame('12.500', $weight);
    }

    public function test_a_line_sold_by_the_kilo_weighs_what_it_was_sold_as(): void
    {
        // Arrange — units agree, so no scale reading was ever asked for; the sold quantity *is*
        // the weight, and it is the same number that leaves the shelf.
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Kilogram, PricingUnit::Kilogram), '8.250');

        // Act
        $weight = $order->totalWeight();

        // Assert
        $this->assertSame('8.250', $weight);
    }

    public function test_the_weighed_lines_add_up_and_the_counted_ones_stay_out_of_it(): void
    {
        // Arrange — two piles on the scale and one counted by hand.
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '500', '4.750');
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Piece), '100');

        // Act
        $weight = $order->totalWeight();

        // Assert — 12.5 + 4.75, and the hundred bags are not kilograms of anything.
        $this->assertSame('17.250', $weight);
    }

    public function test_one_unweighed_line_withholds_the_whole_order_s_weight(): void
    {
        // Arrange — half the parcel is on the shelf, half is not.
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '500');

        // Act
        $weight = $order->totalWeight();

        // Assert — «12.500» under «الوزن» would be read as what the parcel weighs, and it is not.
        $this->assertNull($weight);
    }

    public function test_a_deleted_line_no_longer_weighs_anything(): void
    {
        // Arrange — a size taken off the order before it was shelved.
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '500', '9.000')
            ->delete();

        // Act
        $weight = $order->fresh()->totalWeight();

        // Assert
        $this->assertSame('12.500', $weight);
    }

    public function test_the_order_screen_is_told_what_the_parcel_weighs(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — a string, like every other decimal this API sends.
        $response->assertOk()->assertJsonPath('data.total_weight', '12.500');
    }

    public function test_the_list_carries_the_weight_beside_every_order(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $this->line($order, $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram), '300', '12.500');
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert — the same string the detail endpoint sends, so the card and the page cannot
        // disagree about what the parcel weighs.
        $response->assertOk()->assertJsonPath('data.0.total_weight', '12.500');
    }

    public function test_weighing_the_list_costs_no_query_per_order(): void
    {
        // Arrange — the same page asked for twice, once holding three times as many orders. The
        // shelf behind every line is what the sum needs and what an unguarded `loadMissing()`
        // would fetch one order at a time.
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $weighed = $this->sellableSize(PricingUnit::Piece, PricingUnit::Kilogram);
        $this->line(Order::factory()->create(), $weighed, '300', '12.500');
        // One request first, uncounted: the permission cache and the token's own lookups are
        // cold on the very first call and would show up as a difference between the two
        // measurements that has nothing to do with how many orders are on the page.
        $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Act
        $one = $this->countQueries(fn () => $this->withHeaders($headers)->getJson('/api/v1/orders'));

        foreach (range(1, 2) as $ignored) {
            $this->line(Order::factory()->create(), $weighed, '300', '12.500');
        }

        $three = $this->countQueries(fn () => $this->withHeaders($headers)->getJson('/api/v1/orders'));

        // Assert — eager-loaded, so the count is the same whether the page holds one order or
        // three. It is the growth that is the bug, not the number itself.
        $this->assertSame($one, $three);
    }

    /**
     * How many queries [$request] runs.
     */
    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
