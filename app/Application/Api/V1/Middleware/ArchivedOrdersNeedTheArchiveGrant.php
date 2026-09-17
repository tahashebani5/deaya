<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Middleware;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\Models\Order;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The archive's grant, charged only where the order actually is one.
 *
 * **The second thing in `routes/api.php` that a `can:` cannot express, and for the same shape of
 * reason as the first.** A route sees a permission name, never the row it is about. Three read
 * endpoints are open to a deleted order — the order itself, its history and its payments — and
 * each costs its own grant *plus* `orders.archive.view`, but only when the order it bound turns
 * out to be in the archive. A `can:orders.archive.view` beside them would charge every ordinary
 * reader for a screen they are not looking at.
 *
 * **What this is really stopping is `logs.view`.** Without it, the change log would answer for an
 * order the archive itself refuses to show — a back door into every order ever deleted, opened by
 * the permission that exists so colleagues can be audited. See
 * Docs/orders/ORDER-DELETE-AND-ARCHIVE.md §٦.
 *
 * **A class rather than a closure written beside the routes**, which is where this began: Laravel
 * casts route middleware to a string, so a closure cannot be attached to a route at all. The name
 * is the comment — a route reading `->middleware([ArchivedOrdersNeedTheArchiveGrant::class])` says
 * what it does without anybody opening this file.
 *
 * Silent on a live order, which is every order these three routes are asked about but one.
 */
class ArchivedOrdersNeedTheArchiveGrant
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $order = $request->route('order');

        // Only ever paired with a route that binds `{order}`, and defensive about it anyway: a
        // guard that quietly passes everything when somebody renames a parameter is worse than
        // no guard, but so is a 500 on a route this was attached to by mistake.
        if ($order instanceof Order && $order->trashed()
            && ! $request->user()?->can(PermissionName::ViewOrderArchive->value)) {
            // Named rather than the blanket «ليس لديك صلاحية لتنفيذ هذا الإجراء»: the reader holds
            // the grant this endpoint asks for and is being refused by a fact about *this order*,
            // which is a different sentence and the only one that tells them what to ask for.
            abort(403, 'هذه الطلبية في الأرشيف، وعرضها يحتاج صلاحية عرض أرشيف الطلبات');
        }

        return $next($request);
    }
}
