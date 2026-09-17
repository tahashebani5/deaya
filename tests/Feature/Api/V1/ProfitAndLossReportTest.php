<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [Total Revenue (product + service)] − [Total COGS] = Gross Profit, over a period.
 *
 * Built through factories rather than the real order lifecycle: the report query — reading
 * already-cached columns — is what is under test here, not the write paths that produced them,
 * which have their own tests elsewhere (OrderWorkflowTest, ProductionCostTest).
 *
 * Arrange - Act - Assert throughout.
 */
class ProfitAndLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /** @return array<string, string> */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewProfitAndLossReport);
    }

    public function test_reading_the_report_needs_its_own_permission(): void
    {
        // Arrange
        $headers = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/reports/profit-loss?from=2026-01-01&to=2026-01-31');

        // Assert
        $response->assertForbidden();
    }

    public function test_the_period_is_required(): void
    {
        // Act
        $response = $this->withHeaders($this->viewer())->getJson('/api/v1/reports/profit-loss');

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_revenue_splits_product_from_service_and_nets_cogs_into_gross_profit(): void
    {
        // Arrange — one delivered order: 300 in lines, 50 of chargeable design fee, 120 of COGS
        $order = Order::factory()->status(OrderStatus::Delivered)->create([
            'items_total' => '300.00',
            'design_source' => DesignSource::InHouse,
            'design_fee' => '50.00',
            'total_cogs' => '120.00',
            'delivered_at' => '2026-03-15 10:00:00',
        ]);
        OrderItem::factory()->for($order)->create([
            'material_cost' => '80.00', 'labor_cost' => '30.00', 'overhead_cost' => '10.00', 'cogs' => '120.00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert
        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('300.00', $data['revenue']['product']);
        $this->assertSame('50.00', $data['revenue']['service']);
        $this->assertSame('350.00', $data['revenue']['total']);
        $this->assertSame('80.00', $data['cost_of_goods_sold']['material']);
        $this->assertSame('30.00', $data['cost_of_goods_sold']['labor']);
        $this->assertSame('10.00', $data['cost_of_goods_sold']['overhead']);
        $this->assertSame('120.00', $data['cost_of_goods_sold']['total']);
        $this->assertSame('230.00', $data['gross_profit']); // 350 - 120
        $this->assertSame(1, $data['orders_recognized']);
    }

    public function test_a_design_fee_only_counts_when_it_was_actually_chargeable(): void
    {
        // Arrange — the column carries a stray value, but design_source says we did not charge
        // for it; RecalculateOrderTotals never folded it into grand_total, and the report must
        // agree.
        Order::factory()->status(OrderStatus::Delivered)->create([
            'items_total' => '100.00',
            'design_source' => DesignSource::Customer,
            'design_fee' => '999.00',
            'total_cogs' => '10.00',
            'delivered_at' => '2026-03-10 10:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert
        $response->assertOk()->assertJsonPath('data.revenue.service', '0.00');
    }

    public function test_settled_orders_are_recognised_by_their_own_date_when_delivered_at_is_absent(): void
    {
        // Arrange — a return that settled without ever being marked delivered in this factory
        // state; COALESCE falls back to settled_at.
        Order::factory()->status(OrderStatus::Settled)->create([
            'items_total' => '75.00',
            'total_cogs' => '25.00',
            'delivered_at' => null,
            'settled_at' => '2026-03-20 10:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert
        $response->assertOk()->assertJsonPath('data.revenue.product', '75.00');
    }

    public function test_orders_outside_the_period_are_excluded(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Delivered)->create([
            'items_total' => '500.00', 'total_cogs' => '100.00', 'delivered_at' => '2026-02-15 10:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert
        $response->assertOk();
        $this->assertSame('0.00', $response->json('data.revenue.product'));
        $this->assertSame(0, $response->json('data.orders_recognized'));
    }

    public function test_orders_not_yet_delivered_or_settled_are_excluded(): void
    {
        // Arrange — printed, on the shelf, but not out the door yet
        Order::factory()->status(OrderStatus::Ready)->create([
            'items_total' => '400.00', 'total_cogs' => '90.00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-01-01&to=2026-12-31');

        // Assert
        $response->assertOk()->assertJsonPath('data.orders_recognized', 0);
    }

    public function test_cash_collected_is_independent_of_which_orders_are_recognised(): void
    {
        // Arrange — a deposit taken this month against an order that has not been delivered yet
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        OrderPayment::factory()->forOrder($order)->amount('150.00')->method(PaymentMethod::Cash)->create([
            'type' => OrderPaymentType::Payment, 'paid_at' => '2026-03-05 10:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert — recognised revenue is still zero, but the cash figure sees it
        $response->assertOk();
        $this->assertSame(0, $response->json('data.orders_recognized'));
        $this->assertSame('150.00', $response->json('data.cash_collected'));
    }

    public function test_a_reversal_does_not_count_as_cash_collected(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $payment = OrderPayment::factory()->forOrder($order)->amount('200.00')->create([
            'type' => OrderPaymentType::Payment, 'paid_at' => '2026-03-05 10:00:00',
        ]);
        OrderPayment::factory()->reversing($payment)->create(['paid_at' => '2026-03-06 10:00:00']);

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/reports/profit-loss?from=2026-03-01&to=2026-03-31');

        // Assert — only the genuine payment counts; the reversal has its own type
        $response->assertOk()->assertJsonPath('data.cash_collected', '200.00');
    }
}
