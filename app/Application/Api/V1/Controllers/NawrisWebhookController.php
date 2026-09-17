<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Controller;
use App\Domain\Carrier\Actions\RecordNawrisWebhook;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carrier webhooks
 *
 * Where Nawris tells us a parcel moved.
 *
 * **Log, queue, 200 — and nothing else.** Their delivery of the message and our processing of it
 * are different things, and conflating them means a bug in our status mapping looks to Nawris like
 * a failed webhook and earns a retry storm. The work happens in `ProcessNawrisWebhook`.
 *
 * **No FormRequest, on purpose.** Validation here would answer a malformed body with a 422 and
 * throw the payload away — and they do not re-send. Every body is stored exactly as it arrived and
 * judged afterwards, where the judgement can be corrected and the event replayed.
 */
class NawrisWebhookController extends Controller
{
    use ResponseTrait;

    public function __invoke(Request $request, RecordNawrisWebhook $record): JsonResponse
    {
        $record((array) $request->json()->all());

        return $this->successMessage('تم الاستلام');
    }
}
