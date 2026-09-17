<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Every permission the system recognises.
 *
 * **Permissions are defined by the code; roles are created by the administrator.** That split
 * matters. A permission is only real because something in the codebase checks for it, so
 * letting anyone invent one at runtime would produce a row that grants nothing and a checkbox
 * that lies. Roles are the opposite — pure policy, and entirely the business's to shape.
 *
 * So the administrator's job is: create a role, tick permissions from this catalogue, give it
 * to staff. Adding a *new* permission means adding a case here and guarding an endpoint with
 * it — a code change, because it is one.
 */
enum PermissionName: string
{
    // Access management
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';

    // Seeing what a colleague is paid, and setting it. **Its own permission, deliberately not
    // part of `users.manage`**: assigning somebody a role and knowing everyone's wage are
    // different jobs, and the accountant who needs the second usually has no business with the
    // first. Both halves are one case rather than a view/manage pair, because a wage is one
    // number on one screen — the person trusted to read it is the person who agrees it.
    case ManageUserSalaries = 'users.salary';
    case ManageRoles = 'roles.manage';

    // Customers
    case ViewCustomers = 'customers.view';
    case ManageCustomers = 'customers.manage';

    // Rewriting or removing a note somebody else wrote — on a customer, on a supplier, on
    // whatever gains notes next. Its own permission rather than part of `customers.manage`,
    // because they are different powers: correcting a phone number is bookkeeping, while editing
    // a colleague's sentence under their name is a claim about what they said. Everybody who may
    // read a record may *write* a note on it and change their own — that needs no grant at all.
    //
    // **One permission, not one per kind of record.** The question is «هل يعدّل هذا الموظف كلام
    // زميله؟», and it has one answer per employee. See GENERAL-COMMENTS.md §١.
    case ModerateComments = 'comments.moderate';

    // مجالات العمل — what a customer's shop sells. Reading is split from managing and granted
    // to every role, because anyone recording a customer needs the list to pick from; curating
    // the list itself is a rarer, deliberate job. Same shape as the delivery map, for the same
    // reason.
    case ViewBusinessFields = 'business_fields.view';
    case ManageBusinessFields = 'business_fields.manage';

    // Catalogue
    case ViewProducts = 'products.view';
    case ManageProducts = 'products.manage';

    // What a وسيط product costs us — `product_variants.cost_price`, and the copy of it every
    // order line keeps. **Split from `products.view` on purpose**: taking an order needs the
    // price the customer pays and nothing else, so the clerk entering orders is not shown what
    // the shop paid for the goods. Setting it is `products.manage`, as every other catalogue
    // number is. The same split `inventory.view_cost` already draws over stock.
    case ViewProductCost = 'products.view_cost';

    // Delivery map — cities and the regions inside them share one pair: a region is never
    // administered by anyone who is not also administering its city.
    // The home screen's banners. **One permission, not a view/manage pair**, because nobody
    // reads this list except to change it — the customer app's own endpoint carries no
    // permission at all, and staff have no screen that merely displays posters.
    case ManageBillboards = 'billboards.manage';

    // The support desk. A view/manage pair, unlike billboards: reading what customers are asking
    // is something a whole shift may need while answering them is a job, and the business
    // composes the two however it likes.
    case ViewSupportTickets = 'support.view';
    case ManageSupportTickets = 'support.manage';

    case ViewDeliveryLocations = 'cities.view';
    case ManageDeliveryLocations = 'cities.manage';

    // Who carries the parcels. Separate from the map above: the person who agrees rates with a
    // carrier is not the person who maintains the list of neighbourhoods.
    case ViewShippingCompanies = 'shipping_companies.view';
    case ManageShippingCompanies = 'shipping_companies.manage';

    // The carrier integration's own operations surface. Separate from `shipping_companies.*`,
    // which is the address book: this is the queue of parcels, the webhooks that never matched,
    // and the delivery conflicts a person has to close.
    case ViewCarrierParcels = 'carrier.view';
    case ManageCarrierParcels = 'carrier.manage';

    // Orders. One permission per status the machine can move *into*, so the business composes
    // a designer, a printer and a delivery coordinator out of this list without any of those
    // jobs being named in the code. See OrderStatus::permission().
    //
    // The two dispatch statuses share `DispatchOrders` on purpose: the clerk says "it is going
    // out" and the destination city decides whether that means استلام مكتب or جاري التوصيل, so
    // two grants would make one button succeed for طرابلس and fail for قرجي. The three returns
    // stay separate because a clerk *does* choose which of them happened.
    case ViewOrders = 'orders.view';
    case ManageOrders = 'orders.manage';
    case DiscountOrders = 'orders.discount';
    // Its own grant rather than the discount's: one gives money away and the other asks the
    // customer for more, and a business may reasonably trust a role with exactly one of them.
    case AddOrderAdditionalCost = 'orders.additional_cost';
    case ManageOrderDesigns = 'orders.designs.manage';

    // «هل أُبلِغ الزبون أنّ طلبه جاهز؟» — the mark an employee puts on an order to say they told
    // the customer, and the queue of orders nobody has told yet.
    //
    // **Its own grant rather than a ride on `orders.manage`.** Whoever takes orders and edits
    // them is not necessarily whoever answers for reaching the customer, and the box this opens
    // on the home screen is one person's morning work — put in front of everybody it is noise
    // that teaches the whole shop to scroll past a number. See ORDER-READY-MESSAGE.md §٤.
    case ConfirmReadyMessage = 'orders.ready_message';

    // The عربون, both halves of it: parking an order until the customer pays, and declaring that
    // they have. The counter's work, and not the warehouse's or the press's.
    //
    // **Neither of these is `ConfirmDepositReceipt`.** Declaring a deposit paid is a claim; the
    // tick that says the money is really in the account is a different grant held by a different
    // person, and the domain refuses it to whoever made the claim. Splitting the two *statuses*
    // as well lets the business decide separately who may ask for money and who may say it came
    // — the same reason every other status carries its own.
    case MoveOrderToAwaitingDeposit = 'orders.status.awaiting_deposit';
    case MoveOrderToDepositPaid = 'orders.status.deposit_paid';

    // The warehouse's own grant: it weighs the goods, names the shelf they leave from, and hands
    // the order to the press. Separate from the two production statuses beside it because a
    // different desk does it.
    case MoveOrderToReadyToPrint = 'orders.status.ready_to_print';
    case MoveOrderToDesigning = 'orders.status.designing';
    case MoveOrderToPrinting = 'orders.status.printing';

    // Sending a وسيط job out to the vendor who makes it, and chasing it until it comes back.
    // Its own grant rather than the press's: a different desk does it, and «قيد التصنيع» is a
    // status of its own for the same reason — see OUTSOURCED-PRODUCTS.md.
    case MoveOrderToManufacturing = 'orders.status.manufacturing';
    case MoveOrderToReady = 'orders.status.ready';
    case MoveOrderToShortage = 'orders.status.shortage';
    case DispatchOrders = 'orders.status.dispatch';
    case MarkOrdersDelivered = 'orders.status.delivered';

    // **Recording that the customer took only part of the order**, which shrinks the invoice —
    // see PARTIAL-DELIVERY-DESIGN.md §3, Decision 5, where this was argued twice.
    //
    // Its own grant rather than a ride on `orders.status.delivered`, and the reason is not
    // distrust of drivers: folding it in would widen a permission everybody already holds,
    // silently, without the business ticking a box or being asked. It would also weld the two
    // powers together — the only way to stop one person shrinking invoices would be to stop them
    // marking anything delivered at all.
    //
    // **Granted to the delivery roles from day one** (see `RoleSeeder`), so the observed
    // behaviour is the same as if it had ridden along; what is gained is a switch that can be
    // thrown on its own.
    //
    // Withholds the *fields*, never the move: somebody without it sees «تم الاستلام» exactly
    // as before and delivers in full — the shape `TransitionFields::money()` already uses to keep
    // a driver away from the till without keeping them away from the parcel.
    case RecordPartialDelivery = 'orders.partial_delivery';

    case SettleOrders = 'orders.status.settled';
    case RecordCourierReturn = 'orders.status.returned_courier';
    case RecordCarrierReturn = 'orders.status.returned_carrier';
    case RecordOfficeReturn = 'orders.status.returned_office';
    case ResendOrders = 'orders.status.resend';
    case CancelOrders = 'orders.status.cancelled';

    // Deleting an order, and the archive it goes to. **Not a fourth way of ending one** — that is
    // «إلغاء تام», which says the order happened and then stopped, with a reason attached. A
    // delete says the row should never have been written: a duplicate, a wrong number, somebody's
    // trial. See Docs/orders/ORDER-DELETE-AND-ARCHIVE.md §1.
    //
    // Three grants rather than one, because the three are answerable by different people. Reading
    // the archive is the mildest — it moves nothing. Deleting returns stock to the shelf.
    // Restoring takes it back off again, **at today's cost layers rather than yesterday's**, so
    // the order comes back priced differently from how it left; whoever the business trusts to
    // put an order back is not automatically whoever it trusts to take one out.
    //
    // `orders.archive.view` is also what makes the archive readable *at all*: an order in it is
    // 404 on every route bar the three read ones, and those three demand this on top of their own
    // grant — otherwise `logs.view` alone would be a back door into everything ever deleted.
    case DeleteOrders = 'orders.delete';
    case RestoreOrders = 'orders.restore';
    case ViewOrderArchive = 'orders.archive.view';

    // The money ledger on an order. Four, and the splits that matter are the last two: money
    // going *out* — a refund to the customer, or an entry cancelled as a mistake — is a
    // different decision from money coming in. Taking a deposit is a receptionist's daily work;
    // putting a hand back into the drawer belongs to whoever answers for it.
    //
    // **And forgiving a debt is a third decision again**, which is why it is not folded into the
    // one above it: a refund hands back money the business already holds, while a write-off
    // decides that money it is owed will never arrive. The second is the only one of the four
    // that turns a shortfall into a loss, and it belongs to whoever answers for the books rather
    // than to everyone who may open the drawer.
    //
    // Viewing is separate from `orders.view` on purpose: the person printing the bags sees the
    // order and has no business with what the customer has paid.
    // **Confirming that a عربون actually arrived — and it is an accounting control, not a status
    // move.** Whoever moved the order to «عربون مدفوع» told the shop the customer paid;
    // `ConfirmDepositReceipt` is a second person saying they looked at the account and the money
    // is there. The domain refuses the tick to the person who made the claim, so **at least two
    // users must hold this** or no deposit can ever be confirmed.
    //
    // Its own grant rather than a ride on `orders.payments.record`: posting an entry to the
    // ledger and vouching for money nobody has posted yet are different responsibilities, and
    // the second is the one the books rest on. See Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٥.
    case ConfirmDepositReceipt = 'orders.deposit.confirm';

    case ViewOrderPayments = 'orders.payments.view';
    case RecordOrderPayments = 'orders.payments.record';
    case ReverseOrderPayments = 'orders.payments.reverse';
    case WriteOffOrderPayments = 'orders.payments.write_off';

    // What a unit of production standard-costs at — labour, machine runtime, overhead. Applied
    // automatically when an order enters printing (see ApplyManufacturingRates), so this pair
    // guards only the admin screen that maintains the rate table itself, the same split
    // purchase_orders.* draws between paperwork and the ledger it feeds.
    case ViewManufacturingCostRates = 'manufacturing_cost_rates.view';
    case ManageManufacturingCostRates = 'manufacturing_cost_rates.manage';

    // Stock. One pair covers warehouses, balances and the ledger: whoever may move stock between
    // two warehouses is necessarily administering both, so splitting them would produce a
    // permission that cannot usefully be granted alone. Reading is separate because taking an
    // order needs to know whether stock exists, while moving it is the storekeeper's job.
    case ViewInventory = 'inventory.view';
    case ManageInventory = 'inventory.manage';
    // Its own grant rather than part of `inventory.manage`: correcting what stock is carried at
    // changes the book value of the business without a shelf being touched, which is a different
    // trust level from recording a transfer somebody can walk over and verify.
    case RevalueStock = 'inventory.revalue';
    // Knowing how much is on the shelf and knowing what it was bought for are two levels of
    // trust: the storekeeper counts and does not buy, and the value of the stock is a number
    // read in a meeting rather than on the loading dock. Not part of `inventory.view`, for the
    // same reason `users.salary` is not part of `users.manage`.
    case ViewStockCost = 'inventory.view_cost';

    // Vendors. Its own pair rather than folded into inventory.*: agreeing terms with a supplier
    // and receiving a shipment they sent are different jobs, the same split customers.* draws
    // between the person and what they buy. Posting a stock arrival itself stays under
    // inventory.* — see StockArrivalController — because it is squarely part of the ledger.
    case ViewVendors = 'vendors.view';
    case ManageVendors = 'vendors.manage';

    // Purchase orders. Drafting, editing, sending and cancelling the paperwork is its own pair,
    // the same reasoning vendors.* carries — but *receiving* against one stays under
    // inventory.manage, not this pair: see PurchaseOrderController::receiveArrival(). Posting a
    // shipment is squarely part of the ledger regardless of which door it came in through, and
    // splitting it out here would let someone who may only draft orders also post stock, or the
    // reverse, neither of which this pair is meant to grant.
    case ViewPurchaseOrders = 'purchase_orders.view';
    case ManagePurchaseOrders = 'purchase_orders.manage';
    // Stepping past the 24-hour window a receipt may ordinarily be taken back in. Its own grant
    // rather than part of `inventory.manage`, the same reasoning `inventory.revalue` carries:
    // undoing a receipt nobody can still walk over and verify is a different level of trust from
    // posting one. It waives the *clock* and nothing else — the guards that refuse a reversal
    // once the stock has moved or been repriced are arithmetic, and no grant reaches them.
    case ReverseReceiptAnyTime = 'purchase_orders.reverse_receipt_any_time';

    // Investors. Reading and administering the deals is the usual pair; the three money verbs
    // are split off it for the same reason `orders.payments.*` splits three ways — recording a
    // deposit, paying an investor out and undoing either are different levels of trust, and the
    // person who edits a deal's name is not necessarily the person who hands over cash.
    case ViewInvestors = 'investors.view';
    case ManageInvestors = 'investors.manage';
    case RecordInvestorMoney = 'investors.money.record';
    case ReverseInvestorMoney = 'investors.money.reverse';
    case RecordDealExpenses = 'investor_deals.expenses.record';
    // What an investor's own account holds, and nothing else in the system. Granted to the
    // «مستثمر» role and to no employee — an investor holding `orders.view` would read every
    // order's cost and profit, which OrderResource publishes to anyone who has it.
    case ViewInvestorPortal = 'investor_portal.view';

    // النواقص. The usual view/manage pair, and three verbs split off it for the reason
    // `orders.payments.*` splits three ways — they are different levels of trust.
    //
    // **`shortages.assign` is separate from `shortages.manage`** because routing work and doing it
    // are different jobs: a supervisor hands a shortage to somebody without being trusted to spend
    // money on it, and whoever writes shortages down all day should not thereby be able to move
    // other people's queues. The same argument `orders.ready_message` makes for its own grant.
    //
    // **And recording a supply is split from reversing one.** Recording is a purchase at a
    // counter; reversing is an admission that one was entered wrongly, and it restates a total
    // somebody may already have reported. `orders.payments.record` / `.reverse` draws the line in
    // the same place for the same reason.
    //
    // What is deliberately *not* here is a grant for «مكتمل»: no permission reaches it, because
    // it is written by arithmetic rather than chosen. See ShortageStatus.
    case ViewShortages = 'shortages.view';
    case ManageShortages = 'shortages.manage';
    case AssignShortages = 'shortages.assign';
    case RecordShortageSupplies = 'shortages.supplies.record';
    case ReverseShortageSupplies = 'shortages.supplies.reverse';

    // The company's editable defaults. Its own pair rather than riding on an existing one:
    // everybody's screens read them and almost nobody should change them.
    case ViewCompanySettings = 'settings.view';
    case ManageCompanySettings = 'settings.manage';

    // The audit trail. One permission, not a pair: nothing writes to it by hand, so there is
    // nothing to manage — and reading it is its own decision, because it exposes every change
    // anyone has made to records the reader may not otherwise be allowed to see.
    case ViewActivityLogs = 'logs.view';

    // Profit & loss. Read-only — the report is built entirely from figures every other context
    // already computes and caches (order totals, cost of goods sold, the payment ledger) — but
    // it is the one screen that puts revenue and cost side by side, which is a different
    // sensitivity from being allowed to see either alone.
    case ViewProfitAndLossReport = 'reports.pnl.view';

    // إحصائيات المبيعات. Its own permission rather than a ride on `reports.pnl.view`: this board
    // carries no cost and no margin, only what was sold and how much of it left the warehouse, so
    // gating it behind the one screen that exposes profit would withhold it from exactly the
    // people — the press, the warehouse — whose own work it reports. Nor does it belong to
    // `orders.view`: reading one customer's order is a different decision from reading every
    // customer's revenue at once.
    case ViewSalesStatisticsReport = 'reports.sales.view';

    // Sending a message to every employee's phone at once. Its own permission rather than part
    // of any other: it is not a view of anything, it is a power over other people's attention,
    // and the person who edits products is not automatically the person who may interrupt the
    // whole shop.
    //
    // **A permission rather than a Gate — unlike `users.create`, and deliberately.** The
    // business expects to delegate this (a floor manager announcing a shift change), so it must
    // be a tick box rather than a rule only a deploy can change. Today **no role holds it**, so
    // it is administrators-only through `Gate::before` alone, with nothing seeded and nothing to
    // remove later.
    //
    // Reading one's own notifications is deliberately *not* a permission: every account has a
    // mailbox, and there is nothing to grant.
    case BroadcastNotifications = 'notifications.broadcast';

    public function label(): string
    {
        return match ($this) {
            self::ViewUsers => 'عرض المستخدمين',
            self::ManageUsers => 'إدارة المستخدمين وأدوارهم',
            self::ManageUserSalaries => 'عرض رواتب الموظفين وتعديلها',
            self::ManageRoles => 'إدارة الأدوار والصلاحيات',
            self::ViewCustomers => 'عرض العملاء',
            self::ManageCustomers => 'إضافة وتعديل العملاء',
            self::ModerateComments => 'تعديل وحذف ملاحظات الآخرين',
            self::ViewBusinessFields => 'عرض مجالات العمل',
            self::ManageBusinessFields => 'إضافة وتعديل مجالات العمل',
            self::ViewProducts => 'عرض المنتجات والأسعار',
            self::ManageProducts => 'إضافة وتعديل المنتجات والأسعار',
            self::ViewProductCost => 'عرض سعر تكلفة المنتجات الوسيطة',
            self::ManageBillboards => 'إدارة لوحة الإعلانات في تطبيق العميل',
            self::ViewSupportTickets => 'عرض تذاكر الدعم',
            self::ManageSupportTickets => 'الرد على التذاكر وإسنادها وإغلاقها',
            self::ViewDeliveryLocations => 'عرض مدن ومناطق التوصيل',
            self::ManageDeliveryLocations => 'إضافة وتعديل مدن ومناطق التوصيل',
            self::ViewShippingCompanies => 'عرض شركات التوصيل',
            self::ManageShippingCompanies => 'إضافة وتعديل شركات التوصيل',
            self::ViewCarrierParcels => 'عرض طرود شركة نورس وسجل الإشعارات',
            self::ManageCarrierParcels => 'إعادة الإرسال وإغلاق التعارضات',
            self::ViewOrders => 'عرض الطلبيات',
            self::ManageOrders => 'إضافة وتعديل الطلبيات',
            self::DiscountOrders => 'منح خصم على الطلبية',
            self::AddOrderAdditionalCost => 'إضافة تكلفة إضافية على الطلبية',
            self::ManageOrderDesigns => 'إدارة تصاميم الطلبية واعتمادها',
            self::ConfirmReadyMessage => 'تأكيد إرسال رسالة الجاهزية للزبون',
            self::MoveOrderToAwaitingDeposit => 'تحويل الطلبية إلى انتظار العربون',
            self::MoveOrderToDepositPaid => 'تحويل الطلبية إلى عربون مدفوع',
            self::ConfirmDepositReceipt => 'تأكيد استلام العربون',
            self::MoveOrderToReadyToPrint => 'تحويل الطلبية إلى جاهزة للطباعة',
            self::MoveOrderToDesigning => 'تحويل الطلبية إلى قيد التصميم',
            self::MoveOrderToPrinting => 'تحويل الطلبية إلى قيد الطباعة',
            self::MoveOrderToManufacturing => 'تحويل الطلبية إلى قيد التصنيع',
            self::MoveOrderToReady => 'تحويل الطلبية إلى جاهزة',
            self::MoveOrderToShortage => 'تحويل الطلبية إلى نواقص',
            self::DispatchOrders => 'تسليم الطلبية للتوصيل أو للاستلام من المكتب',
            self::MarkOrdersDelivered => 'تأكيد استلام العميل للطلبية',
            self::RecordPartialDelivery => 'تسجيل تسليم جزئي — يُنقص الفاتورة',
            self::SettleOrders => 'تسوية مبلغ الطلبية',
            self::RecordCourierReturn => 'تسجيل راجع لدى المندوب',
            self::RecordCarrierReturn => 'تسجيل راجع لدى شركة التوصيل',
            self::RecordOfficeReturn => 'تسجيل راجع مكتب',
            self::ResendOrders => 'إعادة إرسال طلبية راجعة',
            self::CancelOrders => 'إلغاء الطلبية',
            self::DeleteOrders => 'حذف الطلبات',
            self::RestoreOrders => 'استعادة الطلبات المحذوفة',
            self::ViewOrderArchive => 'عرض أرشيف الطلبات',

            self::ViewOrderPayments => 'عرض دفعات الطلبية',
            self::RecordOrderPayments => 'تسجيل دفعة على الطلبية',
            self::ReverseOrderPayments => 'إلغاء دفعة أو ردّ مبلغ',
            self::WriteOffOrderPayments => 'شطب فرق مبلغ الطلبية',
            self::ViewManufacturingCostRates => 'عرض معدلات تكلفة التصنيع',
            self::ManageManufacturingCostRates => 'إدارة معدلات تكلفة التصنيع',

            self::ViewInventory => 'عرض المخازن والأرصدة والحركات',
            self::ManageInventory => 'إدارة المخازن وتسجيل حركات المخزون',
            self::RevalueStock => 'تعديل تكلفة دفعات المخزون',
            self::ViewStockCost => 'عرض تكلفة المخزون',
            self::ViewVendors => 'عرض الموردين',
            self::ManageVendors => 'إضافة وتعديل الموردين',
            self::ViewPurchaseOrders => 'عرض أوامر الشراء',
            self::ManagePurchaseOrders => 'إنشاء وتعديل أوامر الشراء وإرسالها وإلغاؤها',
            self::ReverseReceiptAnyTime => 'التراجع عن استلام شحنة بعد انتهاء مهلة الـ٢٤ ساعة',
            self::ViewInvestors => 'عرض المستثمرين وصفقاتهم',
            self::ManageInvestors => 'إضافة وتعديل المستثمرين والصفقات',
            self::RecordInvestorMoney => 'تسجيل إيداع أو تمويل أو سحب لمستثمر',
            self::ReverseInvestorMoney => 'عكس حركة مالية لمستثمر',
            self::RecordDealExpenses => 'تسجيل مصاريف الصفقة',
            self::ViewInvestorPortal => 'بوابة المستثمر — رأس ماله وأرباحه وحدها',
            self::ViewShortages => 'عرض النواقص',
            self::ManageShortages => 'إضافة وتعديل النواقص وتغيير حالتها',
            self::AssignShortages => 'إسناد النواقص إلى الموظفين',
            self::RecordShortageSupplies => 'تسجيل عملية توفير',
            self::ReverseShortageSupplies => 'عكس عملية توفير',
            self::ViewCompanySettings => 'عرض إعدادات الشركة',
            self::ManageCompanySettings => 'تعديل إعدادات الشركة',
            self::ViewActivityLogs => 'عرض سجل النشاطات',
            // Deliberately explicit about the blast radius: whoever ticks this on the roles
            // screen should read what they are granting before they grant it.
            self::BroadcastNotifications => 'إرسال إشعار عام لكل الموظفين',
            self::ViewProfitAndLossReport => 'عرض تقرير الأرباح والخسائر',
            self::ViewSalesStatisticsReport => 'عرض إحصائيات المبيعات',
        };
    }

    /**
     * Used to group the catalogue in a permissions screen.
     */
    public function group(): string
    {
        return match ($this) {
            self::ViewUsers, self::ManageUsers, self::ManageUserSalaries,
            self::ManageRoles => 'الصلاحيات والمستخدمون',
            self::ViewCustomers, self::ManageCustomers,
            self::ModerateComments => 'الملاحظات',
            self::ViewBusinessFields, self::ManageBusinessFields => 'مجالات العمل',
            self::ViewProducts, self::ManageProducts, self::ViewProductCost => 'المنتجات',
            self::ManageBillboards, self::ViewSupportTickets, self::ManageSupportTickets => 'تطبيق العميل',
            self::ViewDeliveryLocations, self::ManageDeliveryLocations => 'مدن ومناطق التوصيل',
            self::ViewShippingCompanies, self::ManageShippingCompanies => 'شركات التوصيل',
            self::ViewCarrierParcels, self::ManageCarrierParcels => 'شحنات نورس',
            self::ViewOrders, self::ManageOrders, self::DiscountOrders,
            self::AddOrderAdditionalCost,
            // Beside the orders themselves rather than in a section of their own: the roles
            // screen is a list to scroll, and three checkboxes are not worth a heading. They are
            // deliberately *not* in «حالات الطلبيات» either — a delete is not a status the
            // machine can move into, which is the whole of what that group collects.
            self::DeleteOrders, self::RestoreOrders, self::ViewOrderArchive,
            self::ManageOrderDesigns,
            // Deliberately not in «حالات الطلبيات»: telling the customer is not a move on the map.
            self::ConfirmReadyMessage => 'الطلبيات',
            self::MoveOrderToAwaitingDeposit, self::MoveOrderToDepositPaid,
            self::MoveOrderToReadyToPrint,
            self::MoveOrderToDesigning, self::MoveOrderToPrinting,
            self::MoveOrderToManufacturing, self::MoveOrderToReady,
            self::MoveOrderToShortage, self::DispatchOrders, self::MarkOrdersDelivered,
            // Beside the move it rides on rather than in «مدفوعات الطلبيات»: it moves money,
            // but it is answered on the status screen by whoever is making that move, and the
            // roles screen is read by somebody deciding what a job involves.
            self::RecordPartialDelivery,
            self::SettleOrders, self::RecordCourierReturn, self::RecordCarrierReturn,
            self::RecordOfficeReturn, self::ResendOrders,
            self::CancelOrders => 'حالات الطلبيات',

            // Beside the money rather than in «حالات الطلبيات»: confirming a عربون arrived is a
            // check on the books, not a move on the map — the two statuses it answers for are
            // over there, and this is the grant that vouches for them.
            self::ConfirmDepositReceipt,
            self::ViewOrderPayments, self::RecordOrderPayments,
            self::ReverseOrderPayments, self::WriteOffOrderPayments => 'مدفوعات الطلبيات',

            self::ViewManufacturingCostRates,
            self::ManageManufacturingCostRates => 'معدلات تكلفة التصنيع',

            self::ViewInventory, self::ManageInventory,
            self::RevalueStock, self::ViewStockCost => 'المخازن والمخزون',
            self::ViewVendors, self::ManageVendors => 'الموردون',
            self::ViewPurchaseOrders, self::ManagePurchaseOrders,
            self::ReverseReceiptAnyTime => 'أوامر الشراء',
            self::ViewInvestors, self::ManageInvestors,
            self::RecordInvestorMoney, self::ReverseInvestorMoney,
            self::RecordDealExpenses, self::ViewInvestorPortal => 'المستثمرون',
            self::ViewShortages, self::ManageShortages, self::AssignShortages,
            self::RecordShortageSupplies, self::ReverseShortageSupplies => 'النواقص',
            self::ViewCompanySettings, self::ManageCompanySettings => 'إعدادات الشركة',
            self::ViewActivityLogs => 'سجل النشاطات',
            self::ViewProfitAndLossReport,
            self::ViewSalesStatisticsReport => 'التقارير المالية',
            self::BroadcastNotifications => 'الإشعارات',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
