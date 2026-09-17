<?php

use App\Application\Api\V1\Middleware\DeshapeArabicInput;
use App\Support\ApiEnvelope;
use App\Support\Exceptions\ProvidesApiFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * The customer app's routes, loaded with the same `api` prefix and middleware group as
         * routes/api.php — so `/api/v1/client/...` behaves exactly like every other endpoint
         * here: the same envelope, the same exception rendering, the same Arabic de-shaping.
         *
         * A file of its own because those routes answer to a different guard (`auth:customer`)
         * rather than to `can:`. See routes/api_client.php for the rest of that reasoning.
         */
        then: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(__DIR__.'/../routes/api_client.php');
        },
    )
    /*
     * **The first scheduled work in this application** — so `schedule:run` needs a cron entry on
     * every box, exactly as `queue:work` needs a worker. Registering it here does nothing on its
     * own, and its absence is silent: the notification tables simply grow forever while every
     * screen keeps working. It belongs on the deployment checklist beside the worker:
     *
     *     * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
     */
    ->withSchedule(function (Schedule $schedule): void {
        // Nightly, off-hours, and without overlapping itself — a prune that ran long once must
        // not have a second copy start on top of it.
        $schedule->command('notifications:prune')->dailyAt('03:30')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // An unauthenticated API request must never redirect to a web login page — there
        // isn't one. Returning null makes Laravel throw AuthenticationException, which the
        // handler below renders as a 401 envelope.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        // Beside `TrimStrings` in the global stack, and doing the same kind of work: what a
        // person typed is tidied before the application ever sees it. Arabic keyed already
        // shaped — «ﺷﺮﻛﺔ» rather than «شركة» — reads identically on every screen and is
        // different bytes to every machine; one such character stored in a name is what stopped
        // order 1228's invoice from being drawn. See App\Support\ArabicText.
        $middleware->append(DeshapeArabicInput::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error leaves through the same envelope as a success, so a client has
        // exactly one response shape to parse. Most specific exception first.

        // The whole error-handling pattern hinges on this one mapping. Any exception that
        // implements ProvidesApiFailure describes its own status, message and field errors, so
        // domain code only ever has to *throw* — it never catches anything to translate it.
        // Matching on the interface rather than a base class means a new failure type is
        // rendered correctly the moment it is written, with no registration step to forget.
        $exceptions->render(function (ProvidesApiFailure $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail($e->userMessage(), $e->httpStatus(), $e->fieldErrors() ?: null)
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail('البيانات المدخلة غير صحيحة', 422, $e->errors())
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail('غير مصرح لك بالدخول', 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail('ليس لديك صلاحية لتنفيذ هذا الإجراء', 403)
                : null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail('العنصر المطلوب غير موجود', 404)
                : null;
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiEnvelope::fail('عدد المحاولات كبير، يرجى المحاولة لاحقاً', 429)
                : null;
        });

        // Anything else. A 4xx HTTP exception carries an actionable message, so keep it;
        // a 5xx must never leak internals unless we are debugging locally.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiEnvelope::fail(
                    $e->getMessage() !== '' ? $e->getMessage() : ApiEnvelope::DEFAULT_ERROR_MESSAGE,
                    $e->getStatusCode(),
                );
            }

            return ApiEnvelope::fail(
                config('app.debug') ? $e->getMessage() : ApiEnvelope::DEFAULT_ERROR_MESSAGE,
                500,
            );
        });
    })->create();
