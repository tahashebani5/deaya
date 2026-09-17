<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Enums\DesignKind;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\DTOs\OrderData;
use App\Domain\Order\DTOs\OrderItemData;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demonstration orders — one resting in every status, plus the scenarios that differ.
 *
 * **Not part of `DatabaseSeeder`, and it must not become part of it.** This writes business
 * records that look real, so it is invoked by name only:
 *
 *     php artisan db:seed --class=OrderDemoSeeder
 *
 * **Every order is driven through the real actions**, never through factory states. An order
 * that reached «راجع مكتب» here actually walked ready → out_for_delivery → returned_courier →
 * returned_carrier → returned_office, so its timeline, its stamped columns and its totals are
 * what the application would have produced. Factory states would have been faster and would have
 * shown nothing — a status with no history behind it is exactly what this seeder exists to avoid
 * demonstrating.
 *
 * **Time is moved deliberately.** Each order is created days in the past and its moves are
 * spread across hours, so "how long did this sit in printing" has a real answer to look at
 * instead of every row sharing one timestamp.
 *
 * Additive: re-running adds a fresh set rather than replacing the last one. Order numbers carry
 * on from wherever they were.
 */
class OrderDemoSeeder extends Seeder
{
    private User $actor;

    private Customer $customer;

    private City $deliveryCity;

    private City $regionCity;

    private City $pickupCity;

    /**
     * Who carries the parcels these orders go out with.
     *
     * A fixture like the city and the customer, because «جاري التوصيل» stopped being a status a
     * clerk can simply select: the transition asks which company took it, and an order out for
     * delivery with nobody named is one nobody can chase.
     */
    private ShippingCompany $carrier;

    private OrderService $orders;

    /**
     * Real "now", captured before the clock is moved at all.
     *
     * Every timestamp below is derived from this rather than from `now()`. `now()` is already
     * frozen by the previous call by the time the next one runs, so building on it compounds:
     * the first version of this seeder walked each order *backwards* through time, ten days per
     * move, and produced an order printed before it was taken.
     */
    private Carbon $anchor;

    public function run(): void
    {
        // The one guard that matters: this writes records that read as genuine orders.
        if (app()->isProduction()) {
            $this->command?->error('OrderDemoSeeder refuses to run in production.');

            return;
        }

        $this->anchor = Carbon::now();
        $this->orders = app(OrderService::class);
        $this->resolveFixtures();

        $this->command?->info('Seeding demonstration orders…');

        $this->oneRestingInEveryStatus();
        $this->theScenariosThatDiffer();

        Carbon::setTestNow();

        $this->report();
    }

    // ─────────────────────────── one per status ───────────────────────────

    private function oneRestingInEveryStatus(): void
    {
        // جديدة — taken and untouched.
        $this->walk(-14, 'جديدة: طلبية للتو', []);

        // قيد التصميم
        $this->walk(-13, 'قيد التصميم: بانتظار اعتماد العميل', [
            [OrderStatus::Designing, null],
        ], DesignSource::Customer);

        // قيد الطباعة
        $this->walk(-12, 'قيد الطباعة', [[OrderStatus::Printing, null]]);

        // جاهزة
        $this->walk(-11, 'جاهزة على الرف', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
        ]);

        // نواقص — declared at intake, which is its only way in.
        $this->walk(-10, 'نواقص: الكمية غير متوفرة عند استلام الطلبية', [
            [OrderStatus::Shortage, null],
        ]);

        // استلام مكتب — waiting at the counter.
        $this->walk(-9, 'استلام مكتب: بانتظار حضور العميل', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OfficePickup, null],
        ], city: $this->pickupCity);

        // جاري التوصيل
        $this->walk(-8, 'جاري التوصيل', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
        ]);

        // تم الاستلام — the successful ending.
        $this->walk(-7, 'تم الاستلام: انتهت بنجاح', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::Delivered, null],
        ]);

        // راجع لدى المندوب
        $this->walk(-6, 'راجع لدى المندوب: العميل رفض الاستلام', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::ReturnedCourier, 'العميل لم يرد على الهاتف'],
        ]);

        // راجع لدى شركة التوصيل
        $this->walk(-5, 'راجع لدى شركة التوصيل', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::ReturnedCourier, 'العنوان غير دقيق'],
            [OrderStatus::ReturnedCarrier, 'سُلّمت لمخزن الشركة'],
        ]);

        // راجع مكتب
        $this->walk(-4, 'راجع مكتب: عادت إلينا', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::ReturnedCourier, 'العميل سافر'],
            [OrderStatus::ReturnedCarrier, null],
            [OrderStatus::ReturnedOffice, 'وصلت المكتب'],
        ]);

        // إعادة إرسال — home from the carrier and going out again.
        $this->walk(-4, 'إعادة إرسال: عادت من الشركة وتُرسَل ثانية', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::ReturnedCourier, 'العميل أجّل الاستلام'],
            [OrderStatus::ReturnedCarrier, null],
            [OrderStatus::Resend, 'العميل طلب إعادة المحاولة'],
        ]);

        // إلغاء تام — never from «جديدة», which is the one open status that cannot be cancelled:
        // a job nobody has started is two taps from being started.
        $this->walk(-3, 'ملغاة: بعد أن دخلت التصميم', [
            [OrderStatus::Designing, null],
            [OrderStatus::Cancelled, 'العميل تراجع عن الطلب'],
        ], DesignSource::Customer);

        // محاسبة — delivered, the money in, and only then settled.
        //
        // **The payment is a step of this scenario, not scene-setting.** «تم التسوية» is refused
        // on an order that still owes anything — see {@see SettlementRequiresFullPayment} — so
        // the collection is recorded through the real action first, exactly as the cashier would
        // when the courier hands the cash over. Walking straight to the last status would not
        // have produced a settled order here; it would have produced the failure.
        $counted = $this->walk(-2, 'محاسبة: سُلّمت وحوسبت', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::Delivered, null],
        ]);

        $this->collect($counted, at: -2, hoursIn: 15);
        $this->move($counted, OrderStatus::Settled, at: -2, hoursIn: 16);
    }

    /**
     * The whole invoice, taken in cash — what the courier hands back on a delivery that went to
     * plan, and what an order owes before it may be settled.
     */
    private function collect(Order $order, int $at, int $hoursIn): void
    {
        Carbon::setTestNow($this->anchor->copy()->addDays($at)->setTime(9, 0)->addHours($hoursIn));

        $order = $order->refresh();

        $this->orders->recordPayment($order, OrderPaymentData::fromArray([
            'amount' => (string) $order->grand_total,
            'method' => PaymentMethod::Cash->value,
        ]), $this->actor);
    }

    // ─────────────────────────── the differences ───────────────────────────

    private function theScenariosThatDiffer(): void
    {
        // A design conversation: the customer turns one down, the next is approved, and only
        // then may it print. The whole point of versions being rows rather than statuses.
        // Moved into «قيد التصميم» first, and not as scene-setting: artwork may only be attached
        // to an order that is in design, and the move itself will not happen without a version
        // to look at. The first version therefore arrives *with* the move, and the conversation
        // below is what happens to it.
        $order = $this->walk(-20, 'محادثة تصميم: نسخة مرفوضة ثم معتمدة', [
            [OrderStatus::Designing, null],
        ], DesignSource::Customer);
        $this->designConversation($order, rejectFirst: true);
        $this->move($order, OrderStatus::Printing, at: -19);
        $this->move($order, OrderStatus::Ready, at: -18);

        // Our own design, which is the one case that adds a fee to the total.
        $inHouse = $this->walk(-17, 'تصميم من عندنا: يُضاف سعره للإجمالي', [
            [OrderStatus::Designing, null],
        ], DesignSource::InHouse, designFee: '150.00');
        $this->designConversation($inHouse, rejectFirst: false);
        $this->move($inHouse, OrderStatus::Printing, at: -16);

        // The same fee sent on a customer-supplied design — and *not* charged. Put beside the
        // one above on purpose: the pair is the rule made visible.
        $this->walk(-17, 'تصميم العميل: نفس الرسم مُرسَل ولا يُحتسب', [], DesignSource::Customer, designFee: '150.00');

        // A discount, which needs its own permission.
        //
        // Deliberately small: the demo line is one product at its minimum quantity, and the
        // domain refuses a discount larger than the order it is taken off — so a round ٥٠ here
        // would only ever demonstrate that rule firing.
        $this->walk(-15, 'خصم: ٥ دنانير على الإجمالي', [
            [OrderStatus::Printing, null],
        ], discount: '5.00');

        // The charge going the other way, beside the discount so the pair can be read together
        // on one screen: two figures either side of the total, never one net number.
        $this->walk(-15, 'تكلفة إضافية: تغليف خاص', [
            [OrderStatus::Printing, null],
        ], additionalCost: '8.00',
            additionalCostReason: AdditionalCostReason::SpecialPackaging,
            additionalCostNote: 'علبة كرتون مزدوجة بطلب العميل');

        // A product the catalogue prices «حسب الطلب» — the clerk names the price.
        $this->manuallyPriced();

        // Several lines at once, so the totals have something to add up.
        $this->multiLine();

        // A city that demands a neighbourhood.
        $this->walk(-13, 'مدينة تشترط المنطقة', [
            [OrderStatus::Printing, null],
        ], city: $this->regionCity, region: $this->regionCity->regions()->first()?->getKey());

        // A shortage that was resolved: the stock came in, and the order rejoined the route it
        // was parked off. This is the shape that matters for the timeline — «نواقص» is a detour
        // an order leaves, not a place it ends.
        $this->walk(-12, 'نواقص ثم توفّرت الكمية', [
            [OrderStatus::Shortage, null],
            [OrderStatus::Printing, 'وصلت الكمية الناقصة من المورد'],
            [OrderStatus::Ready, null],
        ]);

        // Printing went back to design because somebody mistyped the artwork.
        $this->walk(-11, 'رجوع من الطباعة إلى التصميم', [
            [OrderStatus::Designing, null],
            [OrderStatus::Printing, null],
            [OrderStatus::Designing, 'اكتُشف خطأ في التصميم بعد بدء الطباعة'],
        ], DesignSource::None);

        // A full round trip: out, refused, home one link at a time, out again, delivered.
        //
        // The chain is walked rather than jumped on purpose — the parcel is with the courier,
        // who hands it to the company that sent him, who hands it back to us — and «إعادة إرسال»
        // is the one step that *is* a jump, because a parcel on our shelf goes straight back out.
        $this->walk(-10, 'دورة كاملة: راجعة ثم أُعيد إرسالها ووصلت', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::ReturnedCourier, 'العميل غير متواجد'],
            [OrderStatus::ReturnedCarrier, 'سُلّمت لمخزن الشركة'],
            [OrderStatus::ReturnedOffice, 'وصلت المكتب'],
            [OrderStatus::Resend, 'اتُّفق مع العميل على موعد جديد'],
            [OrderStatus::OutForDelivery, null],
            [OrderStatus::Delivered, null],
        ]);

        // Shipped through a carrier, with the tracking a clerk types in.
        $this->walk(-9, 'شحن مع شركة درب برقم تتبع', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
        ], shipping: true);

        // Delivered to someone other than the customer.
        $this->walk(-8, 'مستلم غير العميل', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OutForDelivery, null],
        ], recipient: true);

        // Cancelled after the bags were already printed — the expensive kind.
        $this->walk(-6, 'إلغاء بعد الطباعة: البضاعة موجودة', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::Cancelled, 'العميل أفلس ولم يستلم — البضاعة في المخزن'],
        ]);

        // Collected in person, and completed.
        $this->walk(-5, 'استلام مكتب مكتمل', [
            [OrderStatus::Printing, null],
            [OrderStatus::Ready, null],
            [OrderStatus::OfficePickup, null],
            [OrderStatus::Delivered, null],
        ], city: $this->pickupCity);
    }

    // ─────────────────────────── the machinery ───────────────────────────

    /**
     * Takes an order `$daysAgo` in the past and walks it through the moves given, an hour or
     * two apart, so its timeline reads like a working week rather than one instant.
     *
     * @param  list<array{0: OrderStatus, 1: string|null}>  $moves
     */
    private function walk(
        int $daysAgo,
        string $note,
        array $moves,
        DesignSource $designSource = DesignSource::None,
        string $designFee = '0.00',
        string $discount = '0.00',
        string $additionalCost = '0.00',
        ?AdditionalCostReason $additionalCostReason = null,
        ?string $additionalCostNote = null,
        ?City $city = null,
        ?int $region = null,
        bool $shipping = false,
        bool $recipient = false,
    ): Order {
        Carbon::setTestNow($this->anchor->copy()->addDays($daysAgo)->setTime(9, 0));

        $order = $this->orders->create(new OrderData(
            customerId: (int) $this->customer->getKey(),
            cityId: (int) ($city ?? $this->deliveryCity)->getKey(),
            regionId: $region,
            designSource: $designSource,
            recipientName: $recipient ? 'محمد الصغير (ابن العميل)' : null,
            recipientPhone: $recipient ? '0913334444' : null,
            addressDetails: 'شارع الجمهورية، بجانب الصيدلية',
            notes: $note,
            designFee: $designFee,
            discount: $discount,
            additionalCost: $additionalCost,
            additionalCostReason: $additionalCostReason,
            additionalCostNote: $additionalCostNote,
            // The carrier and the man holding the parcel are no longer taken here: they are
            // asked for at «جاري التوصيل», which is the moment anybody knows them. What is left
            // on the order itself is the number a clerk is given to type in.
            trackingNumber: $shipping ? 'DRB-'.Str::upper(Str::random(8)) : null,
            items: [$this->anItem()],
        ), $this->actor);

        foreach ($moves as $index => [$status, $reason]) {
            $this->move($order, $status, $reason, at: $daysAgo, hoursIn: 3 * ($index + 1));
        }

        Carbon::setTestNow();

        return $order->refresh();
    }

    private function move(Order $order, OrderStatus $to, ?string $reason = null, int $at = 0, int $hoursIn = 3): void
    {
        Carbon::setTestNow($this->anchor->copy()->addDays($at)->setTime(9, 0)->addHours($hoursIn));

        $order = $order->refresh();

        $this->orders->changeStatus($order, $to, $reason, $this->actor, $this->fieldsFor($order, $to));
    }

    /**
     * What the form would have been filled in with, for the moves that ask a question.
     *
     * The seeder walks orders through the real action rather than stamping statuses on them, so
     * it has to answer the same questions a clerk does. Three statuses ask one:
     *
     *   * «قيد التصميم» is refused without artwork to look at, so a version is uploaded to the
     *     customer's library and carried in — but only on the way *in*. An order sent back to
     *     design from printing already has versions on it, and attaching another would be a
     *     second copy of the same logo,
     *   * «جاري التوصيل» wants the carrier — required, because the return chain is answered from
     *     it — and takes the courier's phone if anybody has it,
     *   * «نواقص» is refused outright unless some line says how much is missing, which is the
     *     whole point of that status.
     *
     * «جاهزة» asks for a weight only when the run is priced by the kilo, and the demo lines are
     * all priced by the piece, so nothing is sent for it: a seeded number would be inventing a
     * measurement nobody took.
     *
     * @return array<string, mixed>
     */
    private function fieldsFor(Order $order, OrderStatus $to): array
    {
        if ($to === OrderStatus::Designing) {
            if ($order->designs()->exists()) {
                return [];
            }

            $design = $this->designFor($this->customer, 'الشعار الأزرق');

            return ['design_ids' => [(int) $design->getKey()]];
        }

        if ($to === OrderStatus::OutForDelivery) {
            return [
                'shipping_company_id' => (int) $this->carrier->getKey(),
                'courier_phone' => '0913334444',
            ];
        }

        if ($to === OrderStatus::Shortage) {
            $item = $order->items->first();

            if ($item === null) {
                return [];
            }

            // A tenth of the line, floored at one, so the number reads like a real shortfall
            // rather than the whole order failing to print.
            $missing = max(1, (int) floor((float) $item->quantity / 10));

            return ["shortage_{$item->getKey()}" => (string) $missing];
        }

        return [];
    }

    /**
     * The artwork conversation: version 1, optionally turned down, then version 2 approved.
     */
    private function designConversation(Order $order, bool $rejectFirst): void
    {
        // Whatever the move into «قيد التصميم» carried in, rather than a fresh upload beside it:
        // two identical logos on one order would read as a version history nobody had.
        $first = $order->designs()->orderBy('version')->first()
            ?? $this->orders->addDesign($order, (int) $this->designFor($this->customer, 'الشعار الأزرق')->getKey());

        if (! $rejectFirst) {
            $this->orders->reviewDesign($order, $first, OrderDesignStatus::Approved, null, $this->actor);

            return;
        }

        $this->orders->reviewDesign(
            $order,
            $first,
            OrderDesignStatus::Rejected,
            'الألوان باهتة والشعار صغير',
            $this->actor,
        );

        $second = $this->orders->addDesign($order, (int) $this->designFor($this->customer, 'الشعار الأزرق — نسخة معدّلة')->getKey());

        $this->orders->reviewDesign($order, $second, OrderDesignStatus::Approved, null, $this->actor);
    }

    /** A product the catalogue refuses to price, sold at a number a person named. */
    private function manuallyPriced(): void
    {
        $product = Product::query()
            ->with('variants')
            ->where('pricing_mode', 'quote_on_request')
            ->first();

        if ($product === null || $product->variants->isEmpty()) {
            $this->command?->warn('No quote-on-request product in the catalogue — skipping that scenario.');

            return;
        }

        Carbon::setTestNow($this->anchor->copy()->subDays(14)->setTime(10, 0));

        $this->orders->create(new OrderData(
            customerId: (int) $this->customer->getKey(),
            cityId: (int) $this->deliveryCity->getKey(),
            notes: 'سعر حسب الطلب: الموظف أدخل السعر يدوياً',
            items: [new OrderItemData(
                productId: (int) $product->getKey(),
                productVariantId: (int) $product->variants->first()->getKey(),
                quantity: '500',
                unitPrice: '3.750',
            )],
        ), $this->actor);

        Carbon::setTestNow();
    }

    /** Three lines, so the arithmetic has something to do. */
    private function multiLine(): void
    {
        $items = $this->someItems(3);

        if (count($items) < 2) {
            return;
        }

        Carbon::setTestNow($this->anchor->copy()->subDays(13)->setTime(11, 0));

        $this->orders->create(new OrderData(
            customerId: (int) $this->customer->getKey(),
            cityId: (int) $this->deliveryCity->getKey(),
            notes: 'عدة منتجات في طلبية واحدة',
            items: $items,
        ), $this->actor);

        Carbon::setTestNow();
    }

    // ─────────────────────────── fixtures ───────────────────────────

    /**
     * Everything the orders hang off, taken from what the database already has rather than
     * invented — the point is to see orders against the real catalogue and the real map.
     */
    private function resolveFixtures(): void
    {
        $this->actor = User::query()->whereHas('roles', fn ($q) => $q->where('name', RoleName::Admin->value))->first()
            ?? User::query()->firstOrFail();

        $this->customer = Customer::query()->where('is_active', true)->firstOrFail();

        $this->deliveryCity = City::query()
            ->where('fulfilment_type', FulfilmentType::Delivery)
            ->where('is_region_required', false)
            ->whereNotNull('delivery_price')
            ->firstOrFail();

        $this->regionCity = City::query()
            ->where('is_region_required', true)
            ->whereHas('regions')
            ->firstOrFail();

        $this->pickupCity = City::query()
            ->where('fulfilment_type', FulfilmentType::OfficePickup)
            ->firstOrFail();

        // Whichever carrier the shop already works with, or one to work with. Unlike the fixtures
        // above this may genuinely not exist yet — nothing seeds shipping companies, and a demo
        // run should not fail for want of a row it can write itself.
        $this->carrier = ShippingCompany::query()->firstOrCreate(
            ['name' => 'شركة درب'],
            ['phone' => '0912223344', 'is_active' => true],
        );
    }

    /** One priceable line: the cheapest tier of the first product that has one. */
    private function anItem(): OrderItemData
    {
        return $this->someItems(1)[0];
    }

    /**
     * @return list<OrderItemData>
     */
    private function someItems(int $count): array
    {
        $items = [];

        $products = Product::query()
            ->with('variants.priceTiers')
            ->where('pricing_mode', 'tiered')
            ->where('is_active', true)
            ->get();

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $tier = $variant->priceTiers->sortBy(fn ($t) => (float) $t->min_quantity)->first();

                if ($tier === null) {
                    continue;
                }

                // The tier's own floor, raised to the product's minimum if that is higher —
                // the quote refuses anything under either.
                $quantity = max((float) $tier->min_quantity, (float) $product->min_order_quantity);

                $items[] = new OrderItemData(
                    productId: (int) $product->getKey(),
                    productVariantId: (int) $variant->getKey(),
                    quantity: (string) $quantity,
                    sortOrder: count($items),
                );

                if (count($items) >= $count) {
                    return $items;
                }

                break;
            }
        }

        return $items;
    }

    /**
     * A design in the customer's library, with a real file behind it so a link resolves.
     *
     * A 1×1 PNG rather than an empty file: the row claims a mime type and a size, and demo data
     * that lies about what is on disk is worse than no demo data.
     */
    private function designFor(Customer $customer, string $label): CustomerDesign
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $disk = (string) config('media.customer_designs.disk');
        $path = "customer-designs/{$customer->getKey()}/".Str::uuid()->toString().'.png';

        Storage::disk($disk)->put($path, $bytes);

        /** @var CustomerDesign $design */
        $design = $customer->designs()->create([
            'disk' => $disk,
            'path' => $path,
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'kind' => DesignKind::Image,
            'size_bytes' => strlen($bytes),
            // Unique per customer by partial index, so it varies per row.
            'checksum' => hash('sha256', $path),
            'width_px' => 1,
            'height_px' => 1,
            'label' => $label,
        ]);

        return $design;
    }

    private function report(): void
    {
        $this->command?->newLine();
        $this->command?->info('Orders now in the database, by status:');

        $rows = [];

        foreach (OrderStatus::cases() as $status) {
            $count = Order::query()->where('status', $status)->count();
            $rows[] = [$status->value, $status->label(), $count];
        }

        $this->command?->table(['status', 'الحالة', 'عدد'], $rows);
        $this->command?->info('Total orders: '.Order::query()->count());
    }
}
