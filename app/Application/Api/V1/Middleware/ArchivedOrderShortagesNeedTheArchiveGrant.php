<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Middleware;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Shortage\Models\Shortage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The archive's grant, charged on a shortage that is about an archived order.
 *
 * **The same hole {@see ArchivedOrdersNeedTheArchiveGrant} closes, reached from a new direction.**
 * That class exists because `logs.view` alone would have answered for an order the archive itself
 * refuses to show. A shortage carries `order_id`, the customer's name and what they were short
 * of, so a screen built on `shortages.view` would answer for the same orders just as readily —
 * and this time without even needing the order's id, since the shortages list hands them out.
 *
 * A sibling rather than a parameter on the original: that one reads `$request->route('order')`,
 * and generalising it into something that goes looking for an order on whatever model a route
 * happens to bind is a guard whose behaviour depends on a naming convention. Two short classes
 * that each say plainly what they look at are worth more than one clever one.
 *
 * **The list is not protected by this** and must not be — a page that fetched archived rows and
 * then dropped them would paginate to short pages, and the reader would learn how many were
 * hidden by counting. `ShortageListQuery` filters them in SQL instead, from the same grant.
 *
 * Silent on every shortage whose order is live, and on every manual one, which is all of them
 * but a few.
 */
class ArchivedOrderShortagesNeedTheArchiveGrant
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shortage = $request->route('shortage');

        // Only ever paired with a route that binds `{shortage}`, and defensive about it anyway:
        // a guard that quietly passes everything when somebody renames a parameter is worse than
        // no guard, but so is a 500 on a route this was attached to by mistake.
        // `loadMissing` rather than a bare read: `belongsToAnArchivedOrder()` answers from the
        // loaded relation, and a route-bound model has none — `Model::shouldBeStrict()` turns
        // that into a lazy-loading exception outside production, which is exactly what it is for.
        if ($shortage instanceof Shortage
            && $shortage->loadMissing('order')->belongsToAnArchivedOrder()
            && ! $request->user()?->can(PermissionName::ViewOrderArchive->value)) {
            // Named rather than the blanket «ليس لديك صلاحية», for the reason its sibling gives:
            // the reader holds the grant this endpoint asks for and is being refused by a fact
            // about *this* row, which is a different sentence and the only one that tells them
            // what to ask for.
            abort(403, 'هذا النقص يخصّ طلبية في الأرشيف، وعرضه يحتاج صلاحية عرض أرشيف الطلبات');
        }

        return $next($request);
    }
}
