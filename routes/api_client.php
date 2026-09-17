<?php

use App\Application\Api\V1\Controllers\Client;
use App\Application\Api\V1\Controllers\Client\AuthController;
use App\Application\Api\V1\Controllers\Client\CatalogController;
use App\Application\Api\V1\Controllers\Client\DesignController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer API — v1
|--------------------------------------------------------------------------
| What the customer app reaches. A separate file from routes/api.php, and the
| separation is deliberate twice over.
|
| **A different guard, not a different permission.** Every route in api.php is
| authorised with `can:`, against a user who holds roles. A customer holds no
| roles and never will: what he reaches is his own rows, and the server decides
| that from his token. Mixing the two files would mean every reader checking
| which guard governs which line.
|
| **And no id in any path.** This application has no policy classes, so «he sees
| only his own records» cannot be written as a permission. It is enforced the way
| `investor-portal` enforces it — by there being nothing to tamper with. The
| customer is resolved from the token in every controller here. A route in this
| file that takes a customer id is a bug, not a shortcut.
|
| Routes here feed the same OpenAPI spec at /docs/api.json as api.php does.
*/

Route::prefix('v1/client')->name('client.')->group(function (): void {

    Route::prefix('auth')->name('auth.')->group(function (): void {
        // Throttled: unauthenticated, and worth brute-forcing. The same 6/minute the staff
        // endpoints carry — see routes/api.php.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:6,1')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:6,1')
            ->name('login');

        /*
         * `auth:customer`, never `auth:sanctum`. The two guards share a driver and a token
         * table; what separates them is the provider each names in config/auth.php, and that
         * is the whole of the wall between the two apps.
         */
        Route::middleware('auth:customer')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

    Route::middleware('auth:customer')->group(function (): void {

        /*
         * ── my designs ──────────────────────────────────────────────────────────────────
         *
         * The customer's own artwork. `{design}` is the only id in this file, and it is not
         * route-model bound: `DesignController::ownedDesign()` resolves it through the
         * signed-in customer's relation, so another customer's design is a 404 rather than a
         * row a controller then has to remember to check. That is the same guarantee
         * `scoped()` gives the staff routes, arrived at the only way it can be here — there is
         * no parent segment to scope against.
         *
         * No `show`: the list carries every field, and a design is only ever met in a list —
         * the same call the staff routes make.
         */
        Route::get('designs', [DesignController::class, 'index'])->name('designs.index');
        Route::post('designs', [DesignController::class, 'store'])->name('designs.store');
        Route::patch('designs/{design}', [DesignController::class, 'update'])
            ->whereNumber('design')->name('designs.update');
        Route::delete('designs/{design}', [DesignController::class, 'destroy'])
            ->whereNumber('design')->name('designs.destroy');

        /*
         * ── what is waiting ─────────────────────────────────────────────────────────────
         *
         * One call for every badge the app draws, asked on launch, on resume and when the home
         * screen is pulled down. Several tiles asking separately would be several round trips
         * on the connection where a round trip is the expensive part — see `BadgeController`.
         */
        Route::get('badges', [Client\BadgeController::class, 'index'])->name('badges.index');

        /*
         * ── the billboard ───────────────────────────────────────────────────────────────
         *
         * What the shop is showing on the home screen. The schedule is applied on the server —
         * a banner whose window has closed never leaves it — so this list is simply "what to
         * draw", in order.
         */
        Route::get('billboards', [Client\BillboardController::class, 'index'])
            ->name('billboards.index');

        /*
         * ── the catalogue ───────────────────────────────────────────────────────────────
         *
         * Read-only, and no `can:` — a customer holds no permissions at all. What he may see is
         * decided by the client resources' field lists, and `is_active` is fixed in the
         * controller rather than read from the query string, so the retired half of the
         * catalogue has no way in.
         *
         * `{product}` *is* route-model bound here, unlike `{design}` above, and the difference
         * is ownership: the catalogue belongs to the shop and is the same for everybody, so
         * there is nothing to confine a lookup to. `CatalogController::liveOrFail()` adds the
         * one thing binding cannot express.
         *
         * The quote is a POST although it writes nothing: the quantity and the size belong in a
         * body, and a price that lands in a URL lands in logs and browser history.
         */
        Route::get('product-categories', [CatalogController::class, 'categories'])
            ->name('product-categories.index');

        Route::get('products', [CatalogController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [CatalogController::class, 'show'])
            ->whereNumber('product')->name('products.show');
        Route::post('products/{product}/quote', [CatalogController::class, 'quote'])
            ->whereNumber('product')->name('products.quote');

        /*
         * ── where we deliver ────────────────────────────────────────────────────────────
         *
         * The destination picker. No `can:` and no filter — `RequestOrderRequest` requires a
         * `city_id`, so an app that cannot list the cities cannot place an order at all, and
         * what it may see is decided by `ClientCityResource`'s field list rather than by a
         * permission a customer does not hold.
         *
         * Never paged, and the regions arrive with their cities: a picker somebody scrolls off
         * the end of is a picker that says we do not deliver to their city.
         */
        Route::get('cities', [Client\DeliveryController::class, 'cities'])->name('cities.index');

        /*
         * ── my orders ───────────────────────────────────────────────────────────────────
         *
         * `{order}` is not route-model bound, for the reason `{design}` is not: the lookup is
         * scoped to the signed-in customer inside `OrderService`, so another customer's order
         * never becomes an object. Binding would hand the controller the row and then rely on
         * somebody remembering to check whose it is.
         *
         * The workshop's nineteen statuses reach the app as eight stages — see
         * `CustomerOrderStage`. The `open` filter is asked in those stages and translated to
         * statuses in the controller.
         */
        Route::get('orders', [Client\OrderController::class, 'index'])->name('orders.index');

        // Throttled although it is authenticated: this is the one endpoint in the customer API
        // that creates work for the shop, and a double-tapped button on a slow connection must
        // not put the same order on the review queue twice.
        Route::post('orders', [Client\OrderController::class, 'store'])
            ->middleware('throttle:20,1')->name('orders.store');

        Route::get('orders/{order}', [Client\OrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');

        /*
         * ── support ─────────────────────────────────────────────────────────────────────
         *
         * A thread per question. `{ticket}` is scoped to the signed-in customer inside
         * `SupportService`, like `{order}` and `{design}` above — never route-model bound.
         *
         * **There is no «mark as read» route**, deliberately: `show` marks the thread read,
         * because reading a conversation is what makes it read and a separate call is one the
         * app forgets on the screen where it matters.
         */
        Route::get('support/tickets', [Client\SupportController::class, 'index'])
            ->name('support.tickets.index');
        Route::post('support/tickets', [Client\SupportController::class, 'store'])
            ->middleware('throttle:20,1')->name('support.tickets.store');
        Route::get('support/tickets/{ticket}', [Client\SupportController::class, 'show'])
            ->whereNumber('ticket')->name('support.tickets.show');
        Route::post('support/tickets/{ticket}/messages', [Client\SupportController::class, 'reply'])
            ->whereNumber('ticket')->middleware('throttle:30,1')->name('support.tickets.messages.store');
    });

});
