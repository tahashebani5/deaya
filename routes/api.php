<?php

use App\Application\Api\V1\Controllers\ActivityLogController;
use App\Application\Api\V1\Controllers\AuthController;
use App\Application\Api\V1\Controllers\BillboardController;
use App\Application\Api\V1\Controllers\BusinessFieldController;
use App\Application\Api\V1\Controllers\CarrierController;
use App\Application\Api\V1\Controllers\CityController;
use App\Application\Api\V1\Controllers\CompanySettingController;
use App\Application\Api\V1\Controllers\CustomerCommentController;
use App\Application\Api\V1\Controllers\CustomerController;
use App\Application\Api\V1\Controllers\CustomerDesignController;
use App\Application\Api\V1\Controllers\HealthController;
use App\Application\Api\V1\Controllers\HomeController;
use App\Application\Api\V1\Controllers\InvestorController;
use App\Application\Api\V1\Controllers\InvestorDealController;
use App\Application\Api\V1\Controllers\InvestorPortalController;
use App\Application\Api\V1\Controllers\ManufacturingCostRateController;
use App\Application\Api\V1\Controllers\NawrisWebhookController;
use App\Application\Api\V1\Controllers\NotificationController;
use App\Application\Api\V1\Controllers\OrderController;
use App\Application\Api\V1\Controllers\OrderPaymentController;
use App\Application\Api\V1\Controllers\PermissionController;
use App\Application\Api\V1\Controllers\ProductCategoryController;
use App\Application\Api\V1\Controllers\ProductController;
use App\Application\Api\V1\Controllers\ProductImageController;
use App\Application\Api\V1\Controllers\ProfitAndLossController;
use App\Application\Api\V1\Controllers\PurchaseOrderController;
use App\Application\Api\V1\Controllers\RegionController;
use App\Application\Api\V1\Controllers\RoleController;
use App\Application\Api\V1\Controllers\SalesStatisticsController;
use App\Application\Api\V1\Controllers\ShippingCompanyController;
use App\Application\Api\V1\Controllers\ShortageController;
use App\Application\Api\V1\Controllers\StockArrivalController;
use App\Application\Api\V1\Controllers\StockBatchController;
use App\Application\Api\V1\Controllers\StockItemController;
use App\Application\Api\V1\Controllers\StockItemGroupController;
use App\Application\Api\V1\Controllers\StockMovementController;
use App\Application\Api\V1\Controllers\SupportTicketController;
use App\Application\Api\V1\Controllers\UserController;
use App\Application\Api\V1\Controllers\VendorCommentController;
use App\Application\Api\V1\Controllers\VendorController;
use App\Application\Api\V1\Controllers\WarehouseController;
use App\Application\Api\V1\Controllers\WarehouseStockController;
use App\Application\Api\V1\Middleware\ArchivedOrderShortagesNeedTheArchiveGrant;
use App\Application\Api\V1\Middleware\ArchivedOrdersNeedTheArchiveGrant;
use App\Application\Api\V1\Middleware\VerifyNawrisWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — v1
|--------------------------------------------------------------------------
| Everything is versioned. A published contract is never changed in place;
| breaking changes go into a new `v2` group instead.
|
| These routes are the input to the OpenAPI spec at /docs/api.json — adding a
| route here makes it show up in the docs UI automatically.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    /*
     * The carrier's webhook. Outside `auth:sanctum` because Nawris holds no token of ours — it is
     * guarded instead by a shared secret compared in constant time *and* an IP allowlist, both in
     * `VerifyNawrisWebhook`. The contract this integration was built from records a system where
     * the allowlist was written and never attached; here it is on the route.
     *
     * Throttled as well: an endpoint that moves orders and writes to the ledger should not be a
     * free retry loop for anyone holding the token.
     */
    Route::post('webhooks/nawris', NawrisWebhookController::class)
        ->middleware([VerifyNawrisWebhook::class, 'throttle:120,1'])
        ->name('webhooks.nawris');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        // Throttled: unauthenticated and worth brute-forcing.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:6,1')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:6,1')
            ->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        /*
         * Access is declared here with `can:` rather than checked inside controllers, so the
         * permission a route needs sits next to the route itself — there is no endpoint whose
         * guard you have to open a controller to discover.
         *
         * An administrator satisfies all of these by rule (Gate::before), so none of them ever
         * needs granting to that role.
         */

        // ── the home screen ─────────────────────────────────────────────────────────────
        // The one endpoint in this file with no `can:` beside it. See HomeController: it is the
        // landing screen, and a permission here would show a blank front door to somebody whose
        // job is a single status transition.
        Route::get('home/summary', [HomeController::class, 'summary'])->name('home.summary');

        // ── access management ───────────────────────────────────────────────────────────
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('can:roles.manage')->name('permissions.index');

        Route::apiResource('roles', RoleController::class)
            ->middleware('can:roles.manage');

        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:users.view')->name('users.index');

        // `users.create` is deliberately **not** a PermissionName case — it is a gate ability
        // defined in AppServiceProvider, so it cannot be ticked onto a role from the roles
        // screen and only an administrator satisfies it. Reads like every other guard on this
        // page precisely so that delegating it later changes nothing here.
        Route::post('users', [UserController::class, 'store'])
            ->middleware('can:users.create')->name('users.store');

        Route::get('users/{user}', [UserController::class, 'show'])
            ->middleware('can:users.view')->name('users.show');

        Route::put('users/{user}', [UserController::class, 'update'])
            ->middleware('can:users.manage')->name('users.update');

        Route::patch('users/{user}/roles', [UserController::class, 'syncRoles'])
            ->middleware('can:users.manage')->name('users.roles');

        // Resetting somebody else's password. `users.password` is a gate ability like
        // `users.create` above and for a sharper reason: whoever sets a colleague's password
        // can sign in as them and act under their name in the audit trail.
        Route::patch('users/{user}/password', [UserController::class, 'setPassword'])
            ->middleware('can:users.password')->name('users.password');

        // A wage is guarded apart from the rest of an employee's record: assigning somebody a
        // role and knowing what everyone is paid are different jobs.
        Route::patch('users/{user}/salary', [UserController::class, 'setSalary'])
            ->middleware('can:users.salary')->name('users.salary');

        // No destroy route, for the same reason customers and products have none: an account is
        // stopped, never deleted, so everything it recorded keeps naming somebody who exists.
        Route::patch('users/{user}/activation', [UserController::class, 'setActivation'])
            ->middleware('can:users.manage')->name('users.activation');

        // ── customers ───────────────────────────────────────────────────────────────────
        // No destroy route on purpose: a customer is deactivated, never deleted, so orders
        // and history keep pointing at a row that still exists.
        Route::apiResource('customers', CustomerController::class)
            ->only(['index', 'show'])
            ->middleware('can:customers.view');

        Route::apiResource('customers', CustomerController::class)
            ->only(['store', 'update'])
            ->middleware('can:customers.manage');

        Route::patch('customers/{customer}/activation', [CustomerController::class, 'setActivation'])
            ->middleware('can:customers.manage')->name('customers.activation');

        // A customer's artwork. `scoped()` makes {design} resolve *within* {customer}, so
        // another customer's design id is a 404 by construction rather than by a check somebody
        // has to remember — the same shape products.images and cities.regions already use.
        //
        // No `show`: the list carries every field, and a design is only ever met in a list.
        // No route replaces a file — see CustomerDesignController.
        Route::apiResource('customers.designs', CustomerDesignController::class)
            ->only(['index'])
            ->middleware('can:customers.view')
            ->scoped();

        Route::apiResource('customers.designs', CustomerDesignController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:customers.manage')
            ->scoped();

        // A customer's notes — what staff write to each other about them.
        //
        // The whole set sits behind `customers.view`, including the writes, and that is the
        // decision: a note is a working tool, not a privilege, and anyone who may look a
        // customer up may leave the next person a sentence about them. Who may change *this*
        // note is a per-row question — its author, or a moderator — so it is answered in the
        // controller rather than by a middleware that can only see the route.
        //
        // No `show`: the list carries every field, and a note is only ever met in a list.
        Route::apiResource('customers.comments', CustomerCommentController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:customers.view')
            ->scoped();

        // ── لوحة الإعلانات ──────────────────────────────────────────────────────────────
        // The banners on the customer app's home screen. **One permission rather than the usual
        // view/manage pair**: nobody reads this list except to change it — the app has its own
        // unguarded endpoint, and staff have no screen that merely displays posters.
        //
        // A destroy route exists, unlike customers and products, for the reason cities have one:
        // nothing points at a poster, so a wrong one comes down rather than being lived with.
        Route::apiResource('billboards', BillboardController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:billboards.manage');

        // ── تذاكر الدعم ─────────────────────────────────────────────────────────────────
        // What customers are asking, and the answers. **A view/manage pair**, unlike the
        // billboard above: reading the queue is something a whole shift may need, while
        // answering and closing is a job.
        //
        // No `store` — a ticket is a customer starting a conversation, and the shop opening one
        // on their behalf would be a thread they never asked for.
        Route::get('support/tickets', [SupportTicketController::class, 'index'])
            ->middleware('can:support.view')->name('support.tickets.index');

        Route::get('support/tickets/{ticket}', [SupportTicketController::class, 'show'])
            ->whereNumber('ticket')->middleware('can:support.view')->name('support.tickets.show');

        Route::post('support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])
            ->whereNumber('ticket')->middleware('can:support.manage')->name('support.tickets.messages.store');

        Route::patch('support/tickets/{ticket}/assignment', [SupportTicketController::class, 'assign'])
            ->whereNumber('ticket')->middleware('can:support.manage')->name('support.tickets.assignment');

        Route::post('support/tickets/{ticket}/close', [SupportTicketController::class, 'close'])
            ->whereNumber('ticket')->middleware('can:support.manage')->name('support.tickets.close');

        // ── مجالات العمل ────────────────────────────────────────────────────────────────
        // What a customer's shop sells. Reading is granted to every role — the customer form
        // cannot be filled in without the list — while curating the list is a rarer job, so it
        // is split off exactly as the delivery map's is.
        //
        // A destroy route exists, unlike customers and products, because this is a curated list
        // and a typo in it should be removable. The action refuses once any shop points at the
        // field; deactivation is what retires one in use.
        Route::apiResource('business-fields', BusinessFieldController::class)
            ->only(['index', 'show'])
            ->middleware('can:business_fields.view')
            ->parameters(['business-fields' => 'business_field']);

        Route::apiResource('business-fields', BusinessFieldController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:business_fields.manage')
            ->parameters(['business-fields' => 'business_field']);

        Route::patch('business-fields/{business_field}/activation', [BusinessFieldController::class, 'setActivation'])
            ->middleware('can:business_fields.manage')->name('business-fields.activation');

        // ── catalogue ───────────────────────────────────────────────────────────────────
        // التصنيفات — the headings the catalogue is organised under. Declared *before* the
        // product routes so `products/{product}` never swallows a path of its own; they are a
        // sibling resource rather than a nested one, because a category exists whether or not
        // any product is in it.
        //
        // No pair of its own: whoever may read products needs the categories to read them by,
        // and whoever maintains products maintains the headings they sit under.
        Route::apiResource('product-categories', ProductCategoryController::class)
            ->only(['index', 'show'])
            ->middleware('can:products.view')
            ->parameters(['product-categories' => 'product_category']);

        // The whole order in one call — see ProductCategoryController::reorder().
        //
        // **Declared before the resource routes, and that is load-bearing.** Laravel matches in
        // registration order, so `product-categories/{product_category}` would take «order» as
        // an id and hand the binding a string the database refuses to cast.
        Route::patch('product-categories/order', [ProductCategoryController::class, 'reorder'])
            ->middleware('can:products.manage')->name('product-categories.reorder');

        // A destroy route exists, unlike products themselves, because this is a curated list and
        // a typo in it should be removable. The action refuses once any product points at the
        // category; deactivation is what retires one in use.
        Route::apiResource('product-categories', ProductCategoryController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:products.manage')
            ->parameters(['product-categories' => 'product_category']);

        Route::patch('product-categories/{product_category}/activation', [ProductCategoryController::class, 'setActivation'])
            ->middleware('can:products.manage')->name('product-categories.activation');

        // The picture the catalogue prints above a heading. `POST` rather than `PUT` because it
        // is multipart, exactly as the product image endpoints are.
        Route::post('product-categories/{product_category}/image', [ProductCategoryController::class, 'setImage'])
            ->middleware('can:products.manage')->name('product-categories.image.set');

        Route::delete('product-categories/{product_category}/image', [ProductCategoryController::class, 'removeImage'])
            ->middleware('can:products.manage')->name('product-categories.image.remove');

        // Reading the catalogue and pricing a quantity are everyday work; changing what things
        // cost is not. Hence two permissions rather than one.
        Route::apiResource('products', ProductController::class)
            ->only(['index', 'show'])
            ->middleware('can:products.view');

        // Pricing lives behind an endpoint rather than in the client, so the number a customer
        // is shown and the number written to an order come from the same code.
        Route::post('products/{product}/quote', [ProductController::class, 'quote'])
            ->middleware('can:products.view')->name('products.quote');

        // Same rule as customers: a product is deactivated, never deleted, so past orders keep
        // pointing at a row that still exists.
        Route::apiResource('products', ProductController::class)
            ->only(['store', 'update'])
            ->middleware('can:products.manage');

        Route::patch('products/{product}/activation', [ProductController::class, 'setActivation'])
            ->middleware('can:products.manage')->name('products.activation');

        // scoped() makes {image} resolve *within* {product}, so another product's image id is a
        // 404 rather than something every controller method has to remember to check.
        Route::apiResource('products.images', ProductImageController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['images' => 'image'])
            ->middleware('can:products.manage')
            ->scoped();

        // ── orders ──────────────────────────────────────────────────────────────────────
        // The state machine's guards are *not* here, and that is the one deliberate exception
        // to this file's own rule. The permission a status change costs depends on the status
        // being asked for, which lives in the request body — a route cannot see it. So
        // ChangeOrderStatusRequest::authorize() looks it up from OrderStatus and answers 403,
        // and the route below only asks that the caller be allowed to see the order at all.
        //
        // Same reasoning for the discount and the additional cost: `orders.discount` and
        // `orders.additional_cost` are enforced inside the domain, so they hold for a console
        // command and a future import too, not only for this endpoint.
        //
        // And a third exception, of the same shape, on the three routes that read into the
        // archive: what an archived order costs to read cannot be written as a `can:` either,
        // because it depends on the row the route bound rather than on the route. See
        // {@see ArchivedOrdersNeedTheArchiveGrant}, which is attached to each of the three.
        // Declared *before* the resource: `apiResource` registers `/orders/{order}`, and
        // implicit binding would try to resolve the word "summary" as an order id and 404.
        Route::get('orders/summary', [OrderController::class, 'statusCounts'])
            ->middleware('can:orders.view')->name('orders.summary');

        // The archive: the deleted orders, read through the same list and the same chips. Behind
        // its own grant rather than `orders.view` — deleting is somebody's admission of a mistake,
        // and a shop may reasonably let the whole floor read orders without letting the whole
        // floor read the mistakes.
        //
        // Declared *before* the resource, for the third time in this file and the same reason as
        // `orders/summary` above: `apiResource` registers `/orders/{order}`, and implicit binding
        // would try to resolve the word "archive" as an order id and 404.
        Route::get('orders/archive', [OrderController::class, 'archive'])
            ->middleware('can:orders.archive.view')->name('orders.archive');

        Route::get('orders/archive/summary', [OrderController::class, 'archiveStatusCounts'])
            ->middleware('can:orders.archive.view')->name('orders.archive.summary');

        Route::apiResource('orders', OrderController::class)
            ->only(['index'])
            ->middleware('can:orders.view');

        // **`show` is written out rather than left to `apiResource`, and it is the first
        // `withTrashed()` in this file.** Two things it needs that the resource cannot express: a
        // binding that resolves a deleted order at all, and the archive's own grant beside
        // `orders.view` — which is a closure, and `apiResource` casts its middleware to strings.
        //
        // The convention the first `withTrashed()` sets is deliberately narrow: reading an
        // archived order is opened, and nothing else is. `update` keeps the ordinary binding
        // below, so an archived order is a 404 to every write — which is what stops an edit
        // landing on a row that is in no list.
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->withTrashed()
            ->middleware(['can:orders.view', ArchivedOrdersNeedTheArchiveGrant::class])
            ->name('orders.show');

        Route::apiResource('orders', OrderController::class)
            ->only(['store', 'update'])
            ->middleware('can:orders.manage');

        // **There is a destroy route now, and it does not contradict the reason there was none.**
        // That reason still stands for ending an order: «إلغاء تام» is how the business finishes
        // one, with a reason attached, and it stays in the list wearing it. This deletes the row
        // that should never have been written — a duplicate, a wrong number, somebody's trial —
        // which is a different question with a different answer, and it is asked far less often.
        // Its own grant for that reason. See Docs/orders/ORDER-DELETE-AND-ARCHIVE.md §1.
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])
            ->middleware('can:orders.delete')->name('orders.destroy');

        // And back out again. `withTrashed()` is not decoration here: without it the binding is
        // soft-delete-scoped and this endpoint would 404 on the only orders it exists for.
        //
        // A grant of its own rather than the delete's, because it is the heavier of the two: a
        // restore takes stock back off the shelf, at today's cost layers rather than the ones the
        // order left with.
        Route::post('orders/{order}/restore', [OrderController::class, 'restore'])
            ->withTrashed()
            ->middleware('can:orders.restore')->name('orders.restore');

        Route::post('orders/{order}/status', [OrderController::class, 'changeStatus'])
            ->middleware('can:orders.view')->name('orders.status');

        // Undoing one. `can:` sits here rather than in the request for the same reason the
        // shortage route's does: unlike a status change this endpoint costs one fixed grant
        // whatever the body says. It is the cancellation's own — whoever the business trusts to
        // write an order off is who it trusts to say the write-off was a mistake — and the
        // destination is never in the payload, so there is nothing else for a guard to read.
        Route::post('orders/{order}/reinstate', [OrderController::class, 'reinstate'])
            ->middleware('can:orders.status.cancelled')->name('orders.reinstate');

        // What is missing from each line — and therefore what the customer is charged, since a
        // line is billed for what is left of it. `can:` sits here rather than in the request
        // because unlike a status change this endpoint costs the same grant whatever it says:
        // the person who declares a shortage is the person who corrects one.
        // **What the shelves say this order is short of** — a read, and only a read. The
        // deduction still refuses what it cannot cover on its own terms; this is what lets the
        // «نواقص» screen arrive with its boxes already filled rather than making a foreman work
        // four numbers out of one refusal about a balance.
        //
        // Behind `orders.status.shortage` rather than `orders.view`: the one thing it is for is
        // declaring a shortage, and whoever may not do that has no use for the suggestion.
        Route::get('orders/{order}/stock-shortfall', [OrderController::class, 'stockShortfall'])
            ->middleware('can:orders.status.shortage')->name('orders.stock-shortfall');

        Route::patch('orders/{order}/shortages', [OrderController::class, 'setShortages'])
            ->middleware('can:orders.status.shortage')->name('orders.shortages');

        // «هل أُبلِغ الزبون أنّ طلبه جاهز؟» — a note about a message somebody sent on their own
        // phone, so the only thing this API can do is record that they say they sent it. `can:`
        // sits here rather than in the request for the reason the shortage route's does: this
        // endpoint costs one fixed grant whatever the body says, and the untick is the same
        // decision as the tick.
        Route::patch('orders/{order}/ready-message', [OrderController::class, 'confirmReadyMessage'])
            ->middleware('can:orders.ready_message')->name('orders.ready-message');

        // «هل وصل العربون فعلاً؟» — a second person's check on what the counter claimed when it
        // moved the order to «عربون مدفوع». Its own grant, and the domain refuses it to whoever
        // made the claim, so the two halves are necessarily two people.
        //
        // A route of its own rather than a field on `PATCH /orders/{order}`: anything that could
        // ride along with an ordinary edit would hand the accountant's signature to everybody who
        // may correct a phone number.
        Route::patch('orders/{order}/deposit-receipt', [OrderController::class, 'confirmDepositReceipt'])
            ->middleware('can:orders.deposit.confirm')->name('orders.deposit-receipt');

        // Designs are chosen from the customer's library, never uploaded here. scoped() makes
        // {design} resolve *within* {order}, so another order's design id is a 404 by
        // construction rather than by a check somebody has to remember.
        Route::post('orders/{order}/designs', [OrderController::class, 'storeDesign'])
            ->middleware('can:orders.designs.manage')->name('orders.designs.store');

        Route::post('orders/{order}/designs/{design}/review', [OrderController::class, 'reviewDesign'])
            ->scopeBindings()
            ->middleware('can:orders.designs.manage')->name('orders.designs.review');

        // Bags spoiled producing one line. Guarded by inventory.manage rather than an orders.*
        // permission — it draws stock and posts a FIFO cost the same way a fulfillment does, so
        // it is squarely part of the stock ledger regardless of which controller the route lives
        // on, the same reasoning PurchaseOrderController::receiveArrival() already carries.
        Route::post('orders/{order}/items/{item}/scrap', [OrderController::class, 'storeScrapLoss'])
            ->scopeBindings()
            ->middleware('can:inventory.manage')->name('orders.items.scrap');

        // ── the carrier ─────────────────────────────────────────────────────────────────
        // Two queues that exist because this integration can fail quietly: webhooks that arrived
        // and were never processed, and orders dispatched but never lodged. Reading is its own
        // permission from acting: seeing that a parcel is stuck is not the same authority as
        // re-lodging it or closing a delivery conflict.
        Route::get('carrier/events', [CarrierController::class, 'events'])
            ->middleware('can:carrier.view')->name('carrier.events');

        Route::get('carrier/parcels', [CarrierController::class, 'parcels'])
            ->middleware('can:carrier.view')->name('carrier.parcels');

        Route::get('carrier/not-lodged', [CarrierController::class, 'notLodged'])
            ->middleware('can:carrier.view')->name('carrier.not-lodged');

        Route::post('carrier/orders/{order}/lodge', [CarrierController::class, 'lodge'])
            ->middleware('can:carrier.manage')->name('carrier.lodge');

        Route::post('carrier/orders/{order}/cancel-shipment', [CarrierController::class, 'cancelShipment'])
            ->middleware('can:carrier.manage')->name('carrier.cancel-shipment');

        Route::post('carrier/orders/{order}/resend', [CarrierController::class, 'resend'])
            ->middleware('can:carrier.manage')->name('carrier.resend');

        // Two ways to get an order free again, and which one you want depends on whether the
        // parcel still exists at their end.
        Route::post('carrier/orders/{order}/delete-shipment', [CarrierController::class, 'deleteShipment'])
            ->middleware('can:carrier.manage')->name('carrier.delete-shipment');

        Route::post('carrier/orders/{order}/unlink', [CarrierController::class, 'unlink'])
            ->middleware('can:carrier.manage')->name('carrier.unlink');

        Route::post('carrier/parcels/{parcel}/resolve-conflict', [CarrierController::class, 'resolveConflict'])
            ->middleware('can:carrier.manage')->name('carrier.resolve-conflict');

        // ── an order's money ────────────────────────────────────────────────────────────
        // A ledger, not a balance. There is deliberately no PUT and no DELETE: an entry is
        // written once, and a mistake is undone by writing a second entry beside it — which is
        // the whole answer to "financial entries must be reversible". The stock ledger below
        // is built the same way for the same reason.
        //
        // Reading is its own permission rather than riding on `orders.view`: the person
        // printing the bags sees the order and has no business with what the customer paid.
        // And **money going out has its own permission again** — taking a deposit is a
        // receptionist's daily work, while putting a hand back into the drawer, whether as a
        // refund or as a cancelled entry, belongs to whoever answers for it.
        // Reading, and reading alone, reaches into the archive: «كم قبضنا على هذه الطلبية» is
        // still a fair question about an order somebody deleted, and the answer is in the ledger
        // either way. The write-side routes below keep the ordinary binding and stay 404 on a
        // deleted order — no money is taken against a row that is not in the list.
        Route::get('orders/{order}/payments', [OrderPaymentController::class, 'index'])
            ->withTrashed()
            ->middleware(['can:orders.payments.view', ArchivedOrdersNeedTheArchiveGrant::class])
            ->name('orders.payments.index');

        Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store'])
            ->middleware('can:orders.payments.record')->name('orders.payments.store');

        // Declared *before* the `{payment}` route below: implicit binding would otherwise try to
        // resolve the word "refunds" as a payment id and 404 — the same trap `orders/summary`
        // sits in front of.
        Route::post('orders/{order}/payments/refunds', [OrderPaymentController::class, 'refund'])
            ->middleware('can:orders.payments.reverse')->name('orders.payments.refund');

        // In front of `{payment}` for the same reason "refunds" is. **And behind its own
        // permission rather than `payments.reverse`:** a refund hands back money the business
        // is holding, while this decides that money it is owed will never arrive — the only
        // entry of the four that turns a shortfall into a loss.
        Route::post('orders/{order}/payments/write-offs', [OrderPaymentController::class, 'writeOff'])
            ->middleware('can:orders.payments.write_off')->name('orders.payments.write-off');

        // scopeBindings(): another order's payment id is a 404 by construction rather than by a
        // check somebody has to remember — the same shape orders.designs already uses.
        Route::post('orders/{order}/payments/{payment}/reverse', [OrderPaymentController::class, 'reverse'])
            ->scopeBindings()
            ->middleware('can:orders.payments.reverse')->name('orders.payments.reverse');

        // ── manufacturing cost rates ────────────────────────────────────────────────────
        // What a unit of production standard-costs at — applied automatically when an order
        // enters printing, never typed per job. Reading is separate from managing for the same
        // reason purchase_orders.* splits paperwork from the ledger it feeds.
        Route::apiResource('manufacturing-cost-rates', ManufacturingCostRateController::class)
            ->only(['index', 'show'])
            ->middleware('can:manufacturing_cost_rates.view')
            ->parameters(['manufacturing-cost-rates' => 'manufacturing_cost_rate']);

        Route::apiResource('manufacturing-cost-rates', ManufacturingCostRateController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:manufacturing_cost_rates.manage')
            ->parameters(['manufacturing-cost-rates' => 'manufacturing_cost_rate']);

        Route::patch('manufacturing-cost-rates/{manufacturing_cost_rate}/activation', [ManufacturingCostRateController::class, 'setActivation'])
            ->middleware('can:manufacturing_cost_rates.manage')->name('manufacturing-cost-rates.activation');

        // ── delivery map ────────────────────────────────────────────────────────────────
        // Reading is its own permission because anyone taking an order needs the city and
        // region lists to fill it in; curating that map is a separate, rarer job.
        //
        // Unlike customers and products, a city *is* deletable: it is reference data the
        // business curates, not a record history points back at. That will want revisiting
        // when Orders lands — see DeliveryService::deleteCity().
        Route::apiResource('cities', CityController::class)
            ->only(['index', 'show'])
            ->middleware('can:cities.view');

        Route::apiResource('cities', CityController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:cities.manage');

        // Who carries the parcels. Its own permission pair: the person who agrees rates with
        // a carrier is not the person who maintains the list of neighbourhoods.
        Route::apiResource('shipping-companies', ShippingCompanyController::class)
            ->only(['index', 'show'])
            ->middleware('can:shipping_companies.view');

        Route::apiResource('shipping-companies', ShippingCompanyController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:shipping_companies.manage');

        // Nested and scoped: a region has no life outside its city, so {region} resolves
        // *within* {city} and another city's region id is a 404 by construction.
        Route::apiResource('cities.regions', RegionController::class)
            ->only(['index', 'show'])
            ->middleware('can:cities.view')
            ->scoped();

        Route::apiResource('cities.regions', RegionController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:cities.manage')
            ->scoped();

        // ── vendors ─────────────────────────────────────────────────────────────────────
        // Its own permission pair, split from inventory.* for the same reason customers.* is
        // split from products.*: agreeing terms with a supplier is a different job from
        // receiving what they sent. No destroy route — a vendor is deactivated, never deleted,
        // so every shipment already on record keeps pointing at a real row.
        Route::apiResource('vendors', VendorController::class)
            ->only(['index', 'show'])
            ->middleware('can:vendors.view');

        Route::apiResource('vendors', VendorController::class)
            ->only(['store', 'update'])
            ->middleware('can:vendors.manage');

        Route::patch('vendors/{vendor}/activation', [VendorController::class, 'setActivation'])
            ->middleware('can:vendors.manage')->name('vendors.activation');

        // A supplier's notes — «لا يسلّم قبل الظهر», «المندوب الجديد اسمه سالم».
        //
        // The whole set sits behind `vendors.view`, writes included, exactly as the customer's
        // notes sit behind `customers.view`: a note is a working tool, not a privilege. Who may
        // change *this* note is a per-row question — its author, or somebody holding
        // `comments.moderate` — so it is answered in the controller rather than by a middleware
        // that can only see the route.
        Route::apiResource('vendors.comments', VendorCommentController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:vendors.view')
            ->scoped();

        // ── purchase orders ─────────────────────────────────────────────────────────────
        // Stock ordered ahead of it arriving: new → arrived → completed, with cancelled
        // reachable from either open status. Drafting, editing, sending and cancelling sit
        // behind their own pair, the same split vendors.* draws from inventory.* above — but
        // receiving a shipment against one is guarded by inventory.manage instead, declared
        // beside the resource routes below rather than here, because posting a shipment is
        // squarely part of that area regardless of which door it came in through. See
        // PurchaseOrderController's own docblock.
        //
        // Declared before the resource, so «summary» is read as the word it is: after it,
        // implicit binding would try to resolve it as a purchase order id and 404 — the same
        // trap `orders/summary` above carries a comment about.
        Route::get('purchase-orders/summary', [PurchaseOrderController::class, 'statusCounts'])
            ->middleware('can:purchase_orders.view')->name('purchase-orders.summary');

        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->only(['index', 'show'])
            ->middleware('can:purchase_orders.view');

        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->only(['store', 'update'])
            ->middleware('can:purchase_orders.manage');

        Route::patch('purchase-orders/{purchase_order}/status', [PurchaseOrderController::class, 'changeStatus'])
            ->middleware('can:purchase_orders.manage')->name('purchase-orders.status');

        Route::post('purchase-orders/{purchase_order}/arrivals', [PurchaseOrderController::class, 'receiveArrival'])
            ->middleware('can:inventory.manage')->name('purchase-orders.arrivals');

        // Undoing that receipt sits behind the same guard that posted it — it is the same job on
        // the same document, and somebody who may put stock on a shelf by mistake must be able to
        // take it back off. `purchase_orders.reverse_receipt_any_time` is *not* checked here:
        // it does not open the door, it only waives the 24-hour window once inside, and the
        // controller reads it off the caller for exactly that.
        Route::post('purchase-orders/{purchase_order}/receipt-reversal', [PurchaseOrderController::class, 'reverseReceipt'])
            ->middleware('can:inventory.manage')->name('purchase-orders.receipt-reversal');

        // ── inventory ───────────────────────────────────────────────────────────────────
        // One pair of permissions covers warehouses, balances and the ledger. Splitting them
        // would produce guards that cannot usefully be granted alone: whoever may transfer
        // stock between two warehouses is administering both of them.
        Route::apiResource('warehouses', WarehouseController::class)
            ->only(['index', 'show'])
            ->middleware('can:inventory.view');

        Route::apiResource('warehouses', WarehouseController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:inventory.manage');

        // The materials those shelves are sizes of. Declared before `stock-items` for no reason
        // other than reading order — a group is the thing you create first.
        //
        // A group holds nothing; it is what lets a product name its material once and have every
        // one of its sizes filed automatically. Same permission pair as everything else here.
        Route::apiResource('stock-item-groups', StockItemGroupController::class)
            ->only(['index', 'show'])
            ->middleware('can:inventory.view');

        Route::apiResource('stock-item-groups', StockItemGroupController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:inventory.manage');

        // What the warehouses actually hold. Under the same pair of permissions as the shelves
        // themselves, not `products.manage`: a stock item is what a pile *is*, and many product
        // sizes across different products point at one — so it belongs to whoever administers
        // stock, not to whoever edits the catalogue.
        Route::apiResource('stock-items', StockItemController::class)
            ->only(['index', 'show'])
            ->middleware('can:inventory.view');

        Route::apiResource('stock-items', StockItemController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:inventory.manage');

        // Its own endpoint rather than a field on the update: changing a shelf's unit restamps
        // every balance and cost layer snapshotted against it, in one transaction under the same
        // locks a movement takes. Replaces the old `products/{product}/stock-unit` — a question
        // that belonged to the pile, asked of a product that only shares it.
        Route::patch('stock-items/{stock_item}/unit', [StockItemController::class, 'setUnit'])
            ->middleware('can:inventory.manage')->name('stock-items.unit');

        // Which product sizes draw on this pile, said from the pile's side. `inventory.manage`
        // and not `products.manage`: what is being decided is what a material feeds, and the
        // person who administers the shelves is the one who knows. The product body is untouched
        // — pointing four sizes at one pile used to mean saving three products, each rewriting
        // prices and images the person had no business in.
        //
        // PUT, because the list replaces: it is the whole set every time, `[]` included.
        Route::put('stock-items/{stock_item}/variants', [StockItemController::class, 'setVariants'])
            ->middleware('can:inventory.manage')->name('stock-items.variants');

        // A balance line has no life outside its warehouse, so `scoped()` resolves {stock}
        // *within* {warehouse} — another warehouse's line id is a 404 by construction, the
        // same shape products.images and cities.regions already use.
        //
        // Read-only apart from the alert threshold, and that is the point of the whole context:
        // a quantity is never written by a request. It moves because a movement below explains
        // it, in the same transaction. There is deliberately no PUT on a stock line.
        Route::get('warehouses/{warehouse}/stocks', [WarehouseStockController::class, 'index'])
            ->middleware('can:inventory.view')->name('warehouses.stocks.index');

        // Declared before the `{stock}` route below so that «summary» is read as the word it is
        // rather than as an id somebody could have called a shelf.
        Route::get('warehouses/{warehouse}/stocks/summary', [WarehouseStockController::class, 'summary'])
            ->middleware('can:inventory.view')->name('warehouses.stocks.summary');

        Route::patch('warehouses/{warehouse}/stocks/{stock}/threshold', [WarehouseStockController::class, 'setThreshold'])
            ->scopeBindings()
            ->middleware('can:inventory.manage')->name('warehouses.stocks.threshold');

        // The ledger. One feed to read, four ways to write to it — an arrival has no source, a
        // fulfillment has no destination, an adjustment has a direction instead of either, so
        // each is its own endpoint with its own body rather than one route carrying a type
        // discriminator and four optional fields.
        Route::get('stock-movements', [StockMovementController::class, 'index'])
            ->middleware('can:inventory.view')->name('stock-movements.index');

        Route::prefix('stock-movements')->name('stock-movements.')
            ->middleware('can:inventory.manage')
            ->group(function (): void {
                Route::post('arrivals', [StockMovementController::class, 'arrivals'])->name('arrivals');
                Route::post('transfers', [StockMovementController::class, 'transfers'])->name('transfers');
                Route::post('fulfillments', [StockMovementController::class, 'fulfillments'])->name('fulfillments');
                Route::post('adjustments', [StockMovementController::class, 'adjustments'])->name('adjustments');
            });

        // ── investors ───────────────────────────────────────────────────────────────────
        //
        // The people whose money finances the stock, the deals it finances, and the wallet each
        // one's money sits in. Reading and administering are the usual pair; the money verbs are
        // split off because recording a deposit, paying somebody out and undoing either are
        // different levels of trust — the same split `orders.payments.*` draws.
        //
        // **`investor-portal` is the investor's own door and carries no id at all.** There are no
        // policies in this application, so «he sees his own rows» is enforced by there being
        // nothing to tamper with: the account is resolved from his own user link.
        Route::get('investors', [InvestorController::class, 'index'])
            ->middleware('can:investors.view')->name('investors.index');

        Route::post('investors', [InvestorController::class, 'store'])
            ->middleware('can:investors.manage')->name('investors.store');

        Route::get('investors/{investor}', [InvestorController::class, 'show'])
            ->middleware('can:investors.view')->name('investors.show');

        Route::put('investors/{investor}', [InvestorController::class, 'update'])
            ->middleware('can:investors.manage')->name('investors.update');

        Route::patch('investors/{investor}/activation', [InvestorController::class, 'activation'])
            ->middleware('can:investors.manage')->name('investors.activation');

        Route::get('investors/{investor}/statement', [InvestorController::class, 'statement'])
            ->middleware('can:investors.view')->name('investors.statement');

        Route::post('investors/{investor}/wallet', [InvestorController::class, 'storeWalletEntry'])
            ->middleware('can:investors.money.record')->name('investors.wallet.store');

        Route::get('investor-deals', [InvestorDealController::class, 'index'])
            ->middleware('can:investors.view')->name('investor-deals.index');

        Route::get('investor-deals/{deal}', [InvestorDealController::class, 'show'])
            ->middleware('can:investors.view')->name('investor-deals.show');

        Route::get('investor-deals/{deal}/orders', [InvestorDealController::class, 'orders'])
            ->middleware('can:investors.view')->name('investor-deals.orders.index');

        // **The same question from the order's end** — «هذه الطلبية، من أخذ منها وكم». Behind
        // `investors.view` rather than `orders.view`: it is a statement about somebody's money,
        // and the clerk who books orders has no business reading it.
        Route::get('orders/{order}/investor-shares', [InvestorDealController::class, 'investorShares'])
            ->middleware('can:investors.view')->name('orders.investor-shares.index');

        Route::post('investor-deals/{deal}/close', [InvestorDealController::class, 'close'])
            ->middleware('can:investors.manage')->name('investor-deals.close');

        // The only way a deal is born — on the order it is about. There is no deal form: a deal is
        // one order's paperwork, and the fraction of the goods its partners own is derived from
        // that order's cost, which a deal built by hand would not have. Guarded by
        // `investors.manage` rather than by `purchase_orders.manage`: it creates a deal and moves
        // investors' money, and the buyer who raises an order is not who decides that.
        Route::post('purchase-orders/{purchaseOrder}/investor-funding', [InvestorDealController::class, 'fundPurchaseOrder'])
            ->middleware('can:investors.manage')->name('purchase-orders.investor-funding.store');

        Route::post('investor-deals/{deal}/expenses', [InvestorDealController::class, 'storeExpense'])
            ->middleware('can:investor_deals.expenses.record')->name('investor-deals.expenses.store');

        Route::get('investor-portal/summary', [InvestorPortalController::class, 'summary'])
            ->middleware('can:investor_portal.view')->name('investor-portal.summary');

        Route::get('investor-portal/statement', [InvestorPortalController::class, 'statement'])
            ->middleware('can:investor_portal.view')->name('investor-portal.statement');

        /*
         * Notifications — this account's own mailbox.
         *
         * **Behind no permission, and there is no id for anybody else's mail.** Every account
         * has a mailbox, so there is nothing to grant; the scoping is done by removing the
         * surface, exactly as `investor-portal` above does. A foreign notification id answers
         * 404 rather than 403 — a 403 would confirm the id names something real.
         */
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');

        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('notifications.unread-count');

        // Before `{notification}`, or the bare parameter swallows the literal segment behind it.
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.read-all');

        Route::post('notifications/devices', [NotificationController::class, 'registerDevice'])
            ->name('notifications.devices.store');

        Route::delete('notifications/devices', [NotificationController::class, 'releaseDevice'])
            ->name('notifications.devices.destroy');

        // **The one endpoint here that is guarded, and the only one in the whole API that can
        // put a message on every phone in the company.** Rate limited as well as permissioned:
        // the permission decides who may interrupt everybody, the throttle stops a
        // double-tapped send button doing it twice.
        Route::post('notifications/announcements', [NotificationController::class, 'sendAnnouncement'])
            ->middleware(['can:notifications.broadcast', 'throttle:6,1'])
            ->name('notifications.announcements.store');

        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->whereNumber('notification')->name('notifications.read');

        // The company's editable defaults.
        Route::get('settings', [CompanySettingController::class, 'show'])
            ->middleware('can:settings.view')->name('settings.show');

        Route::put('settings', [CompanySettingController::class, 'update'])
            ->middleware('can:settings.manage')->name('settings.update');

        // The cost layers behind those balances — the first thing in this API that could read
        // them. `PATCH .../cost` is the only write: it changes what a quantity of stock is
        // carried at without moving any stock, which is why it has a grant of its own rather
        // than riding on `inventory.manage`.
        Route::get('stock-batches', [StockBatchController::class, 'index'])
            ->middleware('can:inventory.view')->name('stock-batches.index');

        Route::patch('stock-batches/{stock_batch}/cost', [StockBatchController::class, 'revalue'])
            ->middleware('can:inventory.revalue')->name('stock-batches.revalue');

        // Stock arrivals: a vendor-linked document with one or more lines, sitting on top of the
        // ledger above rather than replacing it — each line still posts through
        // `InventoryService::recordMovement()`. No update or destroy route, the same rule
        // `stock-movements` follows: a posted arrival is never edited.
        Route::get('stock-arrivals', [StockArrivalController::class, 'index'])
            ->middleware('can:inventory.view')->name('stock-arrivals.index');

        Route::post('stock-arrivals', [StockArrivalController::class, 'store'])
            ->middleware('can:inventory.manage')->name('stock-arrivals.store');

        Route::get('stock-arrivals/{stock_arrival}', [StockArrivalController::class, 'show'])
            ->middleware('can:inventory.view')->name('stock-arrivals.show');

        // ── shortages ───────────────────────────────────────────────────────────────────
        // What the shop is short of, written by hand or generated from an order line entering
        // «نواقص». Its own section rather than a corner of the order screen: a shortage outlives
        // the order that produced it, carries money spent chasing it, and belongs to a person
        // rather than to a status.
        //
        // **Five grants, and the splits are deliberate** — see PermissionName. Reading and
        // writing are the usual pair; assigning is separate because routing work is a different
        // job from doing it; and recording a purchase is separate from reversing one, the same
        // three-way split `orders.payments.*` makes.
        //
        // **`summary` is declared before `{shortage}`**, or the router reads the word «summary»
        // as an id and answers 404 — the trap `orders/archive` documents one screen over.
        Route::get('shortages/summary', [ShortageController::class, 'statusCounts'])
            ->middleware('can:shortages.view')->name('shortages.summary');

        Route::get('shortages', [ShortageController::class, 'index'])
            ->middleware('can:shortages.view')->name('shortages.index');

        Route::post('shortages', [ShortageController::class, 'store'])
            ->middleware('can:shortages.manage')->name('shortages.store');

        // **Every route that binds `{shortage}` carries the archive guard**, reading and writing
        // alike. These rows name an order and a customer, so without it this section would answer
        // for every order ever deleted — the hole `ArchivedOrdersNeedTheArchiveGrant` closes in
        // front of `logs.view`, reached from a new direction. See SHORTAGES-DESIGN §٥.
        //
        // The list above needs no guard because `ShortageListQuery` filters archived rows out in
        // SQL: a page that fetched them and then dropped them would paginate short, and a reader
        // would learn how many were hidden by counting.
        Route::get('shortages/{shortage}', [ShortageController::class, 'show'])
            ->middleware(['can:shortages.view', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->name('shortages.show');

        Route::put('shortages/{shortage}', [ShortageController::class, 'update'])
            ->middleware(['can:shortages.manage', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->name('shortages.update');

        Route::patch('shortages/{shortage}/status', [ShortageController::class, 'changeStatus'])
            ->middleware(['can:shortages.manage', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->name('shortages.status');

        Route::patch('shortages/{shortage}/assignee', [ShortageController::class, 'assign'])
            ->middleware(['can:shortages.assign', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->name('shortages.assignee');

        // No destroy route for a supply, and no update: the ledger is append-only and a mistake
        // is corrected by a reversal that names it — `order_payments` is the precedent.
        Route::post('shortages/{shortage}/supplies', [ShortageController::class, 'recordSupply'])
            ->middleware(['can:shortages.supplies.record', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->name('shortages.supplies.store');

        // `scopeBindings()`: {supply} resolves *within* {shortage}, so another shortage's entry
        // is a 404 by construction rather than by a check somebody has to remember. The domain
        // refuses it too — belt and braces on a route that moves money.
        Route::post('shortages/{shortage}/supplies/{supply}/reversal', [ShortageController::class, 'reverseSupply'])
            ->middleware(['can:shortages.supplies.reverse', ArchivedOrderShortagesNeedTheArchiveGrant::class])
            ->scopeBindings()
            ->name('shortages.supplies.reversal');

        // ── reports ─────────────────────────────────────────────────────────────────────
        // Revenue against cost of goods sold, over a period. Its own permission rather than a
        // ride on `orders.view`: this is the one screen that puts every order's money and every
        // order's cost side by side, which is a different sensitivity from either alone.
        Route::get('reports/profit-loss', [ProfitAndLossController::class, 'summary'])
            ->middleware('can:reports.pnl.view')->name('reports.profit-loss');

        // حجم المبيعات وحركة الأكياس over a period. Its own permission rather than a ride on the
        // one above: this board puts no cost or margin on the screen, so the press and the
        // warehouse can be shown their own output without being shown what the shop earns on it.
        Route::get('reports/sales-statistics', [SalesStatisticsController::class, 'summary'])
            ->middleware('can:reports.sales.view')->name('reports.sales-statistics');

        // ── audit trail ─────────────────────────────────────────────────────────────────
        // Every record's history hangs off the record itself, so `{product}` resolves, 404s
        // and — where a resource is scoped — nests exactly as it does on the endpoint beside
        // it. One `/logs?subject_type=…&subject_id=…` endpoint would have had to reimplement
        // all of that, and would have got it wrong for one resource eventually.
        //
        // All of them are behind `logs.view` rather than the permission that guards the record.
        // Reading a history is a different decision from reading the record: it surfaces what
        // *everyone* has done, including people and prices the reader has no other way to see.
        // Someone who may edit products is not automatically someone who may audit their
        // colleagues.
        Route::middleware('can:logs.view')->group(function (): void {
            Route::get('logs', [ActivityLogController::class, 'index'])->name('logs.index');

            Route::get('users/{user}/logs', [UserController::class, 'logs'])->name('users.logs');
            Route::get('roles/{role}/logs', [RoleController::class, 'logs'])->name('roles.logs');
            Route::get('customers/{customer}/logs', [CustomerController::class, 'logs'])->name('customers.logs');
            Route::get('customers/{customer}/comments/{comment}/logs', [CustomerCommentController::class, 'logs'])
                ->scopeBindings()->name('customers.comments.logs');
            Route::get('business-fields/{business_field}/logs', [BusinessFieldController::class, 'logs'])
                ->name('business-fields.logs');
            Route::get('products/{product}/logs', [ProductController::class, 'logs'])->name('products.logs');
            Route::get('product-categories/{product_category}/logs', [ProductCategoryController::class, 'logs'])
                ->name('product-categories.logs');
            Route::get('cities/{city}/logs', [CityController::class, 'logs'])->name('cities.logs');
            // The third and last route that reads into the archive — and the one that needed the
            // guard most: `logs.view` alone would otherwise open the history of every order ever
            // deleted, from a screen built to audit colleagues rather than to browse the archive.
            Route::get('orders/{order}/logs', [OrderController::class, 'logs'])
                ->withTrashed()
                ->middleware(ArchivedOrdersNeedTheArchiveGrant::class)
                ->name('orders.logs');
            Route::get('shipping-companies/{shippingCompany}/logs', [ShippingCompanyController::class, 'logs'])
                ->name('shipping-companies.logs');

            // The archive guard again, for the same reason it is on the order's own history: a
            // shortage's log names its order, so `logs.view` alone would read into the archive
            // through the one screen built to audit colleagues.
            Route::get('shortages/{shortage}/logs', [ShortageController::class, 'logs'])
                ->middleware(ArchivedOrderShortagesNeedTheArchiveGrant::class)
                ->name('shortages.logs');

            // The warehouse and the alert thresholds set on its shelves. Not the movements —
            // those are a ledger rather than a change log, and `/stock-movements?warehouse_id=`
            // is the reader built for them.
            Route::get('warehouses/{warehouse}/logs', [WarehouseController::class, 'logs'])->name('warehouses.logs');

            // The item and the alert thresholds set on its shelves, for the same reason and with
            // the same exclusion as the warehouse above — `/stock-movements?stock_item_id=` is
            // the reader built for its ledger.
            Route::get('stock-items/{stock_item}/logs', [StockItemController::class, 'logs'])
                ->name('stock-items.logs');

            // The material and every size of it — «من غيّر وحدة 25*35؟» is asked of the material.
            Route::get('stock-item-groups/{stock_item_group}/logs', [StockItemGroupController::class, 'logs'])
                ->name('stock-item-groups.logs');

            Route::get('vendors/{vendor}/logs', [VendorController::class, 'logs'])->name('vendors.logs');
            Route::get('vendors/{vendor}/comments/{comment}/logs', [VendorCommentController::class, 'logs'])
                ->scopeBindings()->name('vendors.comments.logs');

            Route::get('stock-arrivals/{stock_arrival}/logs', [StockArrivalController::class, 'logs'])
                ->name('stock-arrivals.logs');

            Route::get('purchase-orders/{purchase_order}/logs', [PurchaseOrderController::class, 'logs'])
                ->name('purchase-orders.logs');

            Route::get('manufacturing-cost-rates/{manufacturing_cost_rate}/logs', [ManufacturingCostRateController::class, 'logs'])
                ->name('manufacturing-cost-rates.logs');

            // The investor and the deal themselves. Not the wallet and not the deal's figures —
            // those are ledgers rather than change logs, and `/investors/{investor}/statement`
            // and the deal's own reader are built for them.
            Route::get('investors/{investor}/logs', [InvestorController::class, 'logs'])->name('investors.logs');
            Route::get('investor-deals/{deal}/logs', [InvestorDealController::class, 'logs'])
                ->name('investor-deals.logs');

            // Scoped like the rest of the nested region routes: another city's region id is a
            // 404 here too, not a history leaked from the wrong place.
            Route::get('cities/{city}/regions/{region}/logs', [RegionController::class, 'logs'])
                ->scopeBindings()
                ->name('cities.regions.logs');
        });
    });
});
