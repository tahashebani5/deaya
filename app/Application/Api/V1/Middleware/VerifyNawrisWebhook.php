<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only thing standing in front of the webhook, so it is two things rather than one.
 *
 * **The contract's own warning is what this fixes rather than copies.** In the system it was
 * compiled from an IP allowlist was written and registered and then *never attached to the route*,
 * leaving a shared bearer token as the sole gate on an endpoint that moves orders to paid and
 * releases money. Anyone holding the token could do the same.
 *
 * So: a constant-time comparison of the shared secret **and** an allowlist that is actually
 * attached. The allowlist is skipped when unconfigured, which is honest rather than lax — an empty
 * list would otherwise refuse every request including theirs, and pretending to enforce something
 * unset is worse than saying it is unset. Filling it in belongs on the deployment checklist.
 */
class VerifyNawrisWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('services.nawris', []);

        $secret = (string) ($config['webhook_secret'] ?? '');

        // Unset means nothing can authenticate, not that everything can.
        if ($secret === '' || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json([
                'status' => false,
                'message' => 'غير مصرّح',
                'data' => null,
            ], 401);
        }

        /** @var list<string> $allowed */
        $allowed = (array) ($config['webhook_ips'] ?? []);

        if ($allowed !== [] && ! in_array((string) $request->ip(), $allowed, true)) {
            return response()->json([
                'status' => false,
                'message' => 'غير مصرّح',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
