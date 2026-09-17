<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The one authoritative definition of the API's response envelope.
 *
 *     { "status": bool, "message": string, "data": mixed }
 *
 * Controllers reach this through {@see ResponseTrait}; thrown exceptions
 * reach it through the handlers in bootstrap/app.php. Both go through here so a
 * failure looks exactly like a success from the client's point of view.
 */
final class ApiEnvelope
{
    public const DEFAULT_SUCCESS_MESSAGE = 'تم بنجاح';

    public const DEFAULT_ERROR_MESSAGE = 'حدث خطأ ما';

    public static function ok(mixed $data = null, string $message = self::DEFAULT_SUCCESS_MESSAGE, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors  Field-level validation errors, if any.
     */
    public static function fail(string $message = self::DEFAULT_ERROR_MESSAGE, int $code = 500, ?array $errors = null): JsonResponse
    {
        $body = [
            'status' => false,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $code);
    }
}
