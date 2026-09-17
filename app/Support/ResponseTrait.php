<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * How controllers answer. Every method here returns the standard envelope
 * (see {@see ApiEnvelope}), so no controller ever writes a response body itself —
 * that guarantee is what lets clients unwrap `data` exactly once.
 */
trait ResponseTrait
{
    /**
     * A successful response. `$data` is normally an API Resource.
     */
    protected function success(mixed $data = null, string $message = ApiEnvelope::DEFAULT_SUCCESS_MESSAGE, int $code = 200): JsonResponse
    {
        return ApiEnvelope::ok($data, $message, $code);
    }

    /**
     * A resource was created (201).
     */
    protected function created(mixed $data = null, string $message = 'تم الإنشاء بنجاح'): JsonResponse
    {
        return ApiEnvelope::ok($data, $message, 201);
    }

    /**
     * A success with nothing to return — `data` is null.
     */
    protected function successMessage(string $message = ApiEnvelope::DEFAULT_SUCCESS_MESSAGE): JsonResponse
    {
        return ApiEnvelope::ok(null, $message);
    }

    /**
     * A paginated collection: items land in `data`, page info in a sibling `meta`.
     *
     * [$extraMeta] is for facts about the *whole* filtered set rather than about this page —
     * today, the per-event counts a history screen's filter chips display. It belongs in `meta`
     * precisely because it survives paging: anything a client could count from `data` would be
     * wrong from page two onwards. The page keys always win, so an endpoint cannot overwrite
     * `total` by accident.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function successWithPagination(
        ResourceCollection $collection,
        string $message = ApiEnvelope::DEFAULT_SUCCESS_MESSAGE,
        array $extraMeta = [],
    ): JsonResponse {
        $paginator = $collection->resource;

        if (! $paginator instanceof AbstractPaginator) {
            return $this->success($collection, $message);
        }

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $collection->collection,
            'meta' => [
                ...$extraMeta,
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * A failed response with no field-level detail.
     */
    protected function error(string $message = ApiEnvelope::DEFAULT_ERROR_MESSAGE, int $code = 500): JsonResponse
    {
        return ApiEnvelope::fail($message, $code);
    }

    /**
     * A 422 carrying per-field validation errors.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    protected function validationErrorsResponse(array $errors, string $message = 'البيانات المدخلة غير صحيحة', int $code = 422): JsonResponse
    {
        return ApiEnvelope::fail($message, $code, $errors);
    }
}
