<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Models\StockBatchRevaluation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealExpense;
use App\Domain\Investor\Models\InvestorDealItem;
use App\Domain\Investor\Models\InvestorDealShare;
use App\Domain\Investor\Models\InvestorDealSupply;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Marketing\Models\Billboard;
use App\Domain\Notification\Models\Notification;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Models\OrderStatusTransition;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderAdditionalCost;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\Settings\Models\CompanySetting;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\StockArrivalItem;
use App\Domain\Vendor\Models\Vendor;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Every kind of record the audit trail can describe — and the application's morph map.
 *
 * `activity_log.subject_type` is a *published* value: clients read it, and rows written today
 * will still be read years from now. A PHP class name is the wrong thing to publish. It leaks
 * `App\Domain\Catalog\Models\Product` into the API, and the day a model moves between contexts
 * every historical row silently stops resolving. The alias is ours to keep stable.
 *
 * Registered as Laravel's morph map in {@see AppServiceProvider}, so it governs
 * *every* polymorphic column — the audit trail's subject and causer, Sanctum's `tokenable`, and
 * Spatie's `model_has_roles`. That is why adding a case here is not optional bookkeeping:
 * `ModelConventionsTest` fails the build when a domain model is missing one.
 *
 * The map is not enforced (`Relation::morphMap`, not `enforceMorphMap`). An unmapped model
 * keeps working with its class name rather than throwing at runtime — the test is a better
 * place to catch the omission than a 500 in front of a user.
 */
enum AuditSubject: string
{
    // Identity
    case User = 'user';
    case Role = 'role';

    // Customers
    case Customer = 'customer';
    case CustomerShop = 'customer_shop';
    case CustomerDesign = 'customer_design';
    case Comment = 'comment';
    case BusinessField = 'business_field';

    // Catalogue
    case Product = 'product';
    case ProductCategory = 'product_category';
    case ProductVariant = 'product_variant';
    case ProductPriceTier = 'product_price_tier';
    case ProductImage = 'product_image';

    // Orders
    case Order = 'order';
    case OrderItem = 'order_item';
    case OrderDesign = 'order_design';
    case OrderStatusTransition = 'order_status_transition';
    case OrderPayment = 'order_payment';
    case ManufacturingCostRate = 'manufacturing_cost_rate';
    case ProductionCostEntry = 'production_cost_entry';

    // Delivery map
    case City = 'city';
    case Region = 'region';
    case ShippingCompany = 'shipping_company';

    // The carrier side. `nawris_webhook_event` is deliberately absent: it is not a
    // business record and is exempt from the audit rules entirely — see the model.
    case NawrisParcel = 'nawris_parcel';
    case NawrisParcelOrder = 'nawris_parcel_order';

    // Inventory
    case StockItemGroup = 'stock_item_group';
    case StockItem = 'stock_item';
    case Warehouse = 'warehouse';
    case WarehouseStock = 'warehouse_stock';
    case StockMovement = 'stock_movement';
    case StockBatch = 'stock_batch';
    case StockBatchConsumption = 'stock_batch_consumption';
    case StockBatchRevaluation = 'stock_batch_revaluation';

    // Vendors
    case Vendor = 'vendor';
    case StockArrival = 'stock_arrival';
    case StockArrivalItem = 'stock_arrival_item';

    // Purchase orders
    case PurchaseOrder = 'purchase_order';
    case PurchaseOrderItem = 'purchase_order_item';
    case PurchaseOrderAdditionalCost = 'purchase_order_additional_cost';

    // Investors
    case Investor = 'investor';
    case InvestorDeal = 'investor_deal';
    case InvestorDealItem = 'investor_deal_item';
    case InvestorDealShare = 'investor_deal_share';
    case InvestorDealSupply = 'investor_deal_supply';
    case InvestorDealExpense = 'investor_deal_expense';
    case InvestorWalletEntry = 'investor_wallet_entry';

    // Shortages
    case Shortage = 'shortage';
    case ShortageSupply = 'shortage_supply';

    // What the shop shows its customers
    case Billboard = 'billboard';

    // What the customer says to the shop
    case SupportTicket = 'support_ticket';
    case TicketMessage = 'ticket_message';

    // Company-wide settings
    case CompanySetting = 'company_setting';

    // **Only announcements ever appear here.** Notifications are exempt from the audit trail as
    // a class — they are system consequences, and a log row recording that a log row arrived is
    // the recursion `ActivityLog` itself is excluded to avoid. An announcement is the one that
    // is not: a person wrote it, it landed on every employee's phone, and it cannot be recalled,
    // so «من أرسل هذا؟» has to have an answer that outlives the retention prune.
    //
    // The alias exists so that row publishes `notification` rather than a PHP class name, the
    // same bargain every other case here makes.
    case Notification = 'notification';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::User => User::class,
            self::Role => Role::class,
            self::Customer => Customer::class,
            self::CustomerShop => CustomerShop::class,
            self::CustomerDesign => CustomerDesign::class,
            self::Comment => Comment::class,
            self::BusinessField => BusinessField::class,
            self::Product => Product::class,
            self::ProductCategory => ProductCategory::class,
            self::ProductVariant => ProductVariant::class,
            self::ProductPriceTier => ProductPriceTier::class,
            self::ProductImage => ProductImage::class,
            self::Order => Order::class,
            self::OrderItem => OrderItem::class,
            self::OrderDesign => OrderDesign::class,
            self::OrderStatusTransition => OrderStatusTransition::class,
            self::OrderPayment => OrderPayment::class,
            self::ManufacturingCostRate => ManufacturingCostRate::class,
            self::ProductionCostEntry => ProductionCostEntry::class,
            self::City => City::class,
            self::Region => Region::class,
            self::ShippingCompany => ShippingCompany::class,
            self::NawrisParcel => NawrisParcel::class,
            self::NawrisParcelOrder => NawrisParcelOrder::class,
            self::StockItemGroup => StockItemGroup::class,
            self::StockItem => StockItem::class,
            self::Warehouse => Warehouse::class,
            self::WarehouseStock => WarehouseStock::class,
            self::StockMovement => StockMovement::class,
            self::StockBatch => StockBatch::class,
            self::StockBatchConsumption => StockBatchConsumption::class,
            self::StockBatchRevaluation => StockBatchRevaluation::class,
            self::Vendor => Vendor::class,
            self::StockArrival => StockArrival::class,
            self::StockArrivalItem => StockArrivalItem::class,
            self::PurchaseOrder => PurchaseOrder::class,
            self::PurchaseOrderItem => PurchaseOrderItem::class,
            self::PurchaseOrderAdditionalCost => PurchaseOrderAdditionalCost::class,
            self::Investor => Investor::class,
            self::InvestorDeal => InvestorDeal::class,
            self::InvestorDealItem => InvestorDealItem::class,
            self::InvestorDealShare => InvestorDealShare::class,
            self::InvestorDealSupply => InvestorDealSupply::class,
            self::InvestorDealExpense => InvestorDealExpense::class,
            self::InvestorWalletEntry => InvestorWalletEntry::class,
            self::Shortage => Shortage::class,
            self::ShortageSupply => ShortageSupply::class,
            self::Billboard => Billboard::class,
            self::SupportTicket => SupportTicket::class,
            self::TicketMessage => TicketMessage::class,
            self::CompanySetting => CompanySetting::class,
            self::Notification => Notification::class,
        };
    }

    /**
     * What to call this kind of record in a history screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'مستخدم',
            self::Role => 'دور',
            self::Customer => 'عميل',
            self::CustomerShop => 'محل عميل',
            self::CustomerDesign => 'تصميم عميل',
            self::Comment => 'ملاحظة',
            self::BusinessField => 'مجال عمل',
            self::Product => 'منتج',
            self::ProductCategory => 'تصنيف منتجات',
            self::ProductVariant => 'مقاس منتج',
            self::ProductPriceTier => 'شريحة سعر',
            self::ProductImage => 'صورة منتج',
            self::Order => 'طلبية',
            self::OrderItem => 'بند طلبية',
            self::OrderDesign => 'تصميم طلبية',
            self::OrderStatusTransition => 'انتقال حالة طلبية',
            self::OrderPayment => 'دفعة طلبية',
            self::ManufacturingCostRate => 'معدل تكلفة تصنيع',
            self::ProductionCostEntry => 'قيد تكلفة إنتاج',
            self::City => 'مدينة',
            self::Region => 'منطقة',
            self::ShippingCompany => 'شركة توصيل',
            self::NawrisParcel => 'طرد نورس',
            self::NawrisParcelOrder => 'طلبية في طرد نورس',
            self::StockItemGroup => 'تصنيف',
            self::StockItem => 'مادة',
            self::Warehouse => 'مخزن',
            self::WarehouseStock => 'رصيد مخزني',
            self::StockMovement => 'حركة مخزنية',
            self::StockBatch => 'دفعة تكلفة',
            self::StockBatchConsumption => 'سحب من دفعة تكلفة',
            self::StockBatchRevaluation => 'تعديل تكلفة دفعة',
            self::Vendor => 'مورد',
            self::StockArrival => 'توريد',
            self::StockArrivalItem => 'بند توريد',
            self::PurchaseOrder => 'أمر شراء',
            self::PurchaseOrderItem => 'بند أمر شراء',
            self::PurchaseOrderAdditionalCost => 'تكلفة إضافية لأمر شراء',
            self::Investor => 'مستثمر',
            self::InvestorDeal => 'صفقة استثمار',
            self::InvestorDealItem => 'مادة في صفقة',
            self::InvestorDealShare => 'حصة مستثمر',
            self::InvestorDealSupply => 'إقرار تمويل',
            self::InvestorDealExpense => 'مصروف صفقة',
            self::InvestorWalletEntry => 'حركة محفظة مستثمر',
            self::Shortage => 'نقص',
            self::ShortageSupply => 'عملية توفير',
            self::Billboard => 'لوحة إعلانات',
            self::SupportTicket => 'تذكرة دعم',
            self::TicketMessage => 'رسالة تذكرة',
            self::CompanySetting => 'إعدادات الشركة',
            self::Notification => 'إشعار عام',
        };
    }

    /**
     * The map Laravel is handed at boot: alias => class.
     *
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::cases() as $subject) {
            $map[$subject->value] = $subject->modelClass();
        }

        return $map;
    }

    /**
     * The alias a model is stored under. Reads it from the live morph map rather than from this
     * enum, so a model that somehow escaped registration is reported as whatever the database
     * would actually hold for it.
     */
    public static function aliasFor(Model $model): string
    {
        return $model->getMorphClass();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $subject) => $subject->value, self::cases());
    }

    /**
     * Whether the given alias is one this application publishes. Used to reject a filter naming
     * a subject type that cannot exist, rather than quietly returning an empty page.
     */
    public static function isKnown(string $alias): bool
    {
        return self::tryFrom($alias) !== null;
    }

    /**
     * Registers the map with Eloquent. Called once, from the service provider.
     */
    public static function register(): void
    {
        Relation::morphMap(self::morphMap());
    }
}
