<?php

namespace App\Providers;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Carrier\Actions\BuildNawrisPayload;
use App\Domain\Carrier\Actions\ResolveNawrisDestination;
use App\Domain\Carrier\Support\NawrisClient;
use App\Domain\Customer\Queries\CustomerOrderActivity;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Listeners\PostEarningsWhenOrderIsFinalised;
use App\Domain\Investor\Listeners\PostPurchasesWhenStockLeaves;
use App\Domain\Investor\Listeners\PostPurchaseWhenScrapIsDrawn;
use App\Domain\Investor\Listeners\PostPurchaseWhenStockIsRedrawn;
use App\Domain\Investor\Listeners\UnwindEarningsWhenOrderIsDeleted;
use App\Domain\Notification\Channels\PushChannel;
use App\Domain\Notification\Listeners\NotifyWhenOrderEntersShortage;
use App\Domain\Notification\Listeners\NotifyWhenOrderStatusChanges;
use App\Domain\Notification\Support\FcmClient;
use App\Domain\Notification\Support\GoogleServiceAccountToken;
use App\Domain\Notification\Listeners\NotifyWhenShortageIsAssigned;
use App\Domain\Order\Events\OrderEnteredShortage;
use App\Domain\Order\Events\OrderProfitFinalised;
use App\Domain\Order\Events\OrderProfitUnwound;
use App\Domain\Order\Events\OrderScrapDrawn;
use App\Domain\Order\Events\OrderStatusChanged;
use App\Domain\Order\Events\OrderShortagesRecorded;
use App\Domain\Order\Events\OrderStockDrawn;
use App\Domain\Order\Events\OrderStockRedrawn;
use App\Domain\Order\Queries\OrderCustomerActivity;
use App\Domain\Shortage\Events\ShortageAssigned;
use App\Domain\Shortage\Listeners\CloseShortagesWhenOrderEnds;
use App\Domain\Shortage\Listeners\ReopenShortagesWhenOrderIsRestored;
use App\Domain\Shortage\Listeners\SyncWhenOrderShortagesChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The customer list asks about orders — who has never placed one, who has not placed one
        // for longest — through a port it declares itself, so the Customer module can be sorted
        // by the orders without depending on them. `Order` already points at `Customer`; the two
        // pointing at each other would be a cycle. This line is the only place the two meet.
        $this->app->bind(CustomerOrderActivity::class, OrderCustomerActivity::class);

        // The carrier client takes its whole configuration as one array, so nothing inside it
        // reaches for `config()` — which is what lets a test hand it a different base URL or a
        // dry-run flag without touching global state.
        // The three carrier classes that need configuration take it as one array, so nothing
        // inside them reaches for `config()` — which is what lets a test hand them a different
        // base URL, a different fallback phone or a dry-run flag without touching global state.
        $this->app->singleton(
            NawrisClient::class,
            fn ($app) => new NawrisClient((array) $app['config']->get('services.nawris', [])),
        );

        $this->app->bind(
            BuildNawrisPayload::class,
            fn ($app) => new BuildNawrisPayload((array) $app['config']->get('services.nawris', [])),
        );

        // The delivery module beside the configuration, because "which of our carriers is this
        // parcel filed under" is a question the setting answers first and the business's own
        // default answers when it is blank — see the action.
        $this->app->bind(
            ResolveNawrisDestination::class,
            fn ($app) => new ResolveNawrisDestination(
                (array) $app['config']->get('services.nawris', []),
                $app->make(DeliveryService::class),
            ),
        );

        // The push side takes its configuration the same way and for the same reason: nothing
        // inside reaches for `config()`, so a test can hand it a dry-run flag or an empty
        // project without touching global state.
        //
        // The token is a singleton because it caches Google's access token — one exchange per
        // process rather than one per device on an announcement going to thirty phones.
        $this->app->singleton(
            GoogleServiceAccountToken::class,
            fn ($app) => new GoogleServiceAccountToken((array) $app['config']->get('services.fcm', [])),
        );

        $this->app->bind(
            FcmClient::class,
            fn ($app) => new FcmClient(
                (array) $app['config']->get('services.fcm', []),
                $app->make(GoogleServiceAccountToken::class),
            ),
        );

        $this->app->bind(
            PushChannel::class,
            fn ($app) => new PushChannel((array) $app['config']->get('services.fcm', [])),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polymorphic columns store a short alias — 'product' — instead of a PHP class name.
        // Registered before anything else because it changes how Eloquent both writes *and*
        // matches every morph column in the schema: the audit trail's subject and causer,
        // Sanctum's tokenable, and Spatie's model_has_roles. See AuditSubject for why, and the
        // migration that rewrote the rows written before it.
        AuditSubject::register();

        // **Orders announces, Investment listens.** The dependency runs one way — Investment
        // reads Orders through its Service and Orders knows nothing about investors — so the
        // moment a sale's profit becomes final is an event rather than a call. A direct call
        // would be a loop the container cannot build: ChangeOrderStatus → InvestorService →
        // OrderService → ChangeOrderStatus. Synchronous on purpose: it runs inside the
        // transaction that moved the status, so the money and the status land together or not
        // at all.
        Event::listen(OrderProfitFinalised::class, PostEarningsWhenOrderIsFinalised::class);

        // **And its counterpart, for an order that leaves the books altogether.** A delete
        // archives the row, so `ProfitAndLossSummaryQuery` stops counting the sale — while the
        // `profit` rows it posted at «تم الاستلام» would go on standing in three wallets.
        // Re-dispatching the event above cannot serve: `PostDealEarningsForOrder` reads the order
        // through a soft-delete-scoped query and returns early on an archived one, having
        // reversed nothing. See §٢٫١ of Docs/orders/ORDER-DELETE-AND-ARCHIVE.md.
        Event::listen(OrderProfitUnwound::class, UnwindEarningsWhenOrderIsDeleted::class);
        Event::listen(OrderStockDrawn::class, PostPurchasesWhenStockLeaves::class);
        Event::listen(OrderScrapDrawn::class, PostPurchaseWhenScrapIsDrawn::class);

        // **A draw beside a settled one, not a correction of it** — which is why a restore has
        // an event of its own rather than a second dispatch of `OrderStockDrawn`: its fresh draw
        // is paid against the movement, so the payment the *first* draw earned is left standing.
        // That first sale really completed: the delete handed its priced layers to the company
        // at what it paid, not back to the deal. See {@see OrderStockRedrawn}. Synchronous like
        // the ones above, and for the same reason.
        Event::listen(OrderStockRedrawn::class, PostPurchaseWhenStockIsRedrawn::class);

        // **Orders announces, the notification centre listens** — the same one-way dependency,
        // for a different reason: Notification reads Orders to build its sentence, and Orders
        // must never learn that notifications exist.
        //
        // **But this listener is queued and deferred to after commit, unlike the three above.**
        // Those are synchronous inside the transaction on purpose, because they move money and
        // must land with the status or not at all. Telling people is the opposite bargain: a
        // failed push must not roll back an order, and an announcement about a transaction that
        // then rolled back cannot be un-sent. See NotifyWhenOrderEntersShortage.
        Event::listen(OrderEnteredShortage::class, NotifyWhenOrderEntersShortage::class);

        // Fired by every transition, not only the interesting ones — the listener holds the list
        // of what is worth a bell, so a second listener can want a different subset without
        // touching Orders. Same queued, after-commit bargain as the line above.
        Event::listen(OrderStatusChanged::class, NotifyWhenOrderStatusChanges::class);

        /*
         * **Orders announces, the shortages section mirrors** — the same one-way dependency once
         * more, and the reason `Domain/Order` gained exactly one line for this whole feature:
         * `SetOrderShortages` is the only writer of `shortage_quantity`, so its single event
         * covers declaring a shortage, receiving against it, and correcting it from the order
         * screen alike.
         *
         * Queued and after commit, the bargain the two lines above make rather than the one the
         * money listeners make. A mirror of a transaction that then rolled back would put a
         * shortage on the board that nobody is short of — and because the reconciliation is
         * declarative, running it a moment late costs nothing and running it twice changes
         * nothing. See Docs/shortages/SHORTAGES-DESIGN.md §٣.
         */
        Event::listen(OrderShortagesRecorded::class, SyncWhenOrderShortagesChange::class);

        /*
         * **An order that ends stops being chased**, whether it ended by cancellation or by
         * delete — a distinction that matters a great deal to the order and not at all to a sack
         * nobody is going to buy now. Two events into one listener, because the reaction is one
         * reaction.
         *
         * Neither reverses a purchase already made against a shortage: the cash left the till and
         * the goods exist. §٧٫٣ of the design document, and `CloseShortagesForOrder`.
         */
        Event::listen(OrderStatusChanged::class, [CloseShortagesWhenOrderEnds::class, 'handleCancellation']);
        Event::listen(OrderProfitUnwound::class, [CloseShortagesWhenOrderEnds::class, 'handleDeletion']);

        // And the restore puts them back, by re-running the same reconciliation over lines that
        // still carry the quantities they were archived with.
        Event::listen(OrderStockRedrawn::class, ReopenShortagesWhenOrderIsRestored::class);

        // An audience of one, unlike every other notification here — the work now belongs to a
        // named person. See ShortageAssignedToYou.
        Event::listen(ShortageAssigned::class, NotifyWhenShortageIsAssigned::class);

        // Turns three silent classes of bug into loud exceptions everywhere except
        // production: lazy-loaded relations (N+1), reading an attribute that was never
        // selected, and assigning an attribute the model does not have. Off in production so
        // a newly-introduced N+1 degrades performance rather than returning a 500.
        Model::shouldBeStrict(! $this->app->isProduction());

        // An administrator passes every authorization check, always — no permission has to be
        // granted to them and none can be forgotten.
        //
        // `null` rather than `false` for everyone else: returning false here would be a final
        // verdict that short-circuits the real policies and permission checks. Returning null
        // means "no opinion", letting the normal rules decide.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // Creating a staff account is the administrator's alone — **for now**.
        //
        // A Gate ability rather than a {@see PermissionName} case, and that is the entire point:
        // a permission is a tick box on the roles screen, so "administrators only" would last
        // exactly until somebody ticked it onto «محاسب». Nothing can grant this one. It is not
        // in the catalogue, so it never appears on that screen, and the rule is here in the one
        // file that already says what an administrator is.
        //
        // Delegating it later is one deliberate edit: delete this line and add a case to
        // PermissionName. The route does not change — it already reads `can:users.create`, the
        // same as every other guarded route in api.php, and starts meaning the permission.
        Gate::define('users.create', fn (User $user) => $user->isAdmin());

        // Resetting somebody else's password — the administrator's alone, and a Gate for the
        // same reason as the line above rather than a weaker version of it.
        //
        // **This one is a takeover, not an edit.** Whoever sets a colleague's password can sign
        // in as them and act under their name in the audit trail, so it must not be a tick box
        // on the roles screen that somebody grants «to save the manager a phone call». Changing
        // one's *own* password is a different endpoint with a different guard: it asks for the
        // current password, because the account holder knows it and a stolen unlocked phone is
        // the risk there.
        Gate::define('users.password', fn (User $user) => $user->isAdmin());
    }
}
