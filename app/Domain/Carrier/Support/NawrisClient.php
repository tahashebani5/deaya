<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Support;

use App\Domain\Carrier\DTOs\NawrisResponse;
use App\Domain\Carrier\Exceptions\NawrisIsNotConfigured;
use App\Domain\Carrier\Exceptions\NawrisRejectedRequest;
use App\Domain\Carrier\Exceptions\NawrisRequestFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every HTTP call to Nawris, and nothing else.
 *
 * **This class and `BuildNawrisPayload` are the only two files that know anything about Nawris's
 * own shapes.** That is deliberate and it is the plan's answer to building against a contract
 * nobody has verified against the carrier: when reality turns out to differ, two files change and
 * the domain does not.
 *
 * **Four quirks in their envelope, all absorbed here:**
 *
 * 1. **Failures arrive as HTTP 200.** A logical error is `{"success": 0, "error_msg": "…"}` with a
 *    successful status line, so checking the status code alone treats every failure as a success —
 *    and writes a parcel row for a shipment that does not exist.
 * 2. **The payload key varies**: `result` on some endpoints, `feed` on others. Accept either,
 *    default to empty.
 * 3. **Delete and cancel use a different envelope** — success is `success == 1` and there is no
 *    payload at all — so they cannot go through the create/edit handler.
 * 4. **Credentials are body fields, not headers**, and are merged into every request including
 *    the two GETs.
 *
 * **No `try`/`catch` anywhere**, per RULES.md §5. A transport failure is converted by
 * `Http::throw()`'s callback; a logical rejection is raised by reading the body ourselves.
 *
 * The raw request and response go to their own log channel. `authentication_key` never does.
 */
final class NawrisClient
{
    private const CREDENTIAL_KEYS = ['authentication_key', 'main_client_code'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    // ── writes ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addOrder(array $payload): NawrisResponse
    {
        return $this->post('add-order', $payload, 'إنشاء الشحنة');
    }

    /**
     * The whole payload again, plus the code. **Not a patch** — a field left out is left untouched
     * at their end rather than cleared, which is why `BuildNawrisPayload` always builds the
     * complete thing.
     *
     * @param  array<string, mixed>  $payload
     */
    public function editOrder(array $payload): NawrisResponse
    {
        return $this->post('edit-order', $payload, 'تعديل الشحنة');
    }

    /**
     * Pull a parcel back before it moves.
     *
     * Uses the second envelope — see {@see postSimple()}.
     */
    public function deleteOrder(string $code): NawrisResponse
    {
        return $this->postSimple('delete-order', ['search_Key' => $code], 'حذف الشحنة');
    }

    /** Call off a live shipment. The second envelope again. */
    public function cancelOrder(string $code): NawrisResponse
    {
        return $this->postSimple('canceled', ['code' => $code, 'type' => '1'], 'إلغاء الشحنة');
    }

    /** On demand only — never on a timer. There is no polling loop in this integration. */
    public function searchOrder(string $code): NawrisResponse
    {
        return $this->post('search-order', ['search_Key' => $code], 'الاستعلام عن الشحنة');
    }

    /**
     * Send a returned parcel out again.
     *
     * `$orderCode` is our own order's code. The contract describes this field as *a new local
     * order id*, because the system it was compiled from mints a fresh order for a re-send and we
     * do not — «إعادة إرسال» is the same order here. Whether they accept a repeated value is one
     * of the things only a real call can settle.
     */
    public function resendRequest(string $code): NawrisResponse
    {
        return $this->post(
            'resend-request',
            // **`type` 1 — the same parcel again — so `order_code` is not sent at all.** Their
            // published table calls it «كود الطرد الجديد» and marks it needed only when `type`
            // is 2, which is the "send it as a brand-new parcel" case we do not use: our second
            // journey is a new row on our side and the same goods on theirs.
            ['code' => $code, 'type' => '1'],
            'إعادة إرسال الشحنة',
        );
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────

    /**
     * Their geography. Cached by the caller — it changes rarely, and it is only ever read to
     * populate our own city and region mapping.
     *
     * @return array<int, array<string, mixed>>
     */
    public function governments(): array
    {
        return $this->get('get-government', 'قراءة المحافظات')->rows();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function areas(string $governmentId): array
    {
        return $this->get('get-area/'.rawurlencode($governmentId), 'قراءة المناطق')->rows();
    }

    // ── the wire ─────────────────────────────────────────────────────────────────────────

    /**
     * The create/edit/search envelope: a payload under `result` or `feed`, and a `success` flag
     * that may be absent when everything went well.
     *
     * @param  array<string, mixed>  $body
     */
    private function post(string $endpoint, array $body, string $operation): NawrisResponse
    {
        $response = $this->send('post', $endpoint, $body, $operation);
        $decoded = $this->decode($response);

        $this->guardLogicalFailure($decoded, $operation);

        return new NawrisResponse($this->unwrap($decoded), $decoded);
    }

    /**
     * Delete and cancel, which say success as `success == 1` and carry no payload.
     *
     * A separate path rather than a flag on {@see post()}: the two envelopes agree on nothing
     * except the transport, and one method reading both would be a method that has to guess.
     *
     * @param  array<string, mixed>  $body
     */
    private function postSimple(string $endpoint, array $body, string $operation): NawrisResponse
    {
        $response = $this->send('post', $endpoint, $body, $operation);
        $decoded = $this->decode($response);

        if ((string) ($decoded['success'] ?? '0') !== '1') {
            throw NawrisRejectedRequest::make($operation, $this->reason($decoded));
        }

        return new NawrisResponse([], $decoded);
    }

    private function get(string $endpoint, string $operation): NawrisResponse
    {
        $response = $this->send('get', $endpoint, [], $operation);
        $decoded = $this->decode($response);

        $this->guardLogicalFailure($decoded, $operation);

        return new NawrisResponse($this->unwrap($decoded), $decoded);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $endpoint, array $body, string $operation): Response
    {
        $this->guardConfigured();

        $payload = array_merge($body, $this->credentials());

        $this->log('request', $endpoint, $this->scrub($payload));

        // `throw()` with a callback rather than a `catch`: it turns a transport failure into a
        // typed domain exception without a try block, which is what keeps `ErrorHandlingTest`
        // green. It fires only on a 4xx/5xx — their logical failures come back as 200 and are
        // caught by `guardLogicalFailure()` below.
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->throw(function (Response $failed) use ($operation): void {
                $this->log('failure', $operation, ['status' => $failed->status(), 'body' => $failed->body()]);

                throw NawrisRequestFailed::make($operation, $failed->status());
            })
            ->{$method}($endpoint, $method === 'get' ? $payload : $payload);

        $this->log('response', $endpoint, ['status' => $response->status(), 'body' => $response->body()]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * **The quirk that matters most.** `success: 0` beside HTTP 200 is a failure, and a caller
     * that trusted the status line would go on to write a parcel row with a null code.
     *
     * Absent `success` is treated as success: the read endpoints do not send one.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function guardLogicalFailure(array $decoded, string $operation): void
    {
        if (! array_key_exists('success', $decoded)) {
            return;
        }

        if ((string) $decoded['success'] === '0') {
            throw NawrisRejectedRequest::make($operation, $this->reason($decoded));
        }
    }

    /**
     * Their payload, from whichever key it came under.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function unwrap(array $decoded): array
    {
        foreach (['result', 'feed', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return [];
    }

    /**
     * Everything they said about why, kept whole.
     *
     * A bare «فشل الطلب» gives support nothing to work with, so the `errors` object is folded in
     * beside `error_msg` rather than discarded.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function reason(array $decoded): ?string
    {
        $parts = [];

        foreach (['error_msg', 'message', 'msg'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
                break;
            }
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $flat = json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE);

            if (is_string($flat) && $flat !== '[]' && $flat !== '{}') {
                $parts[] = $flat;
            }
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'authentication_key' => (string) $this->config['authentication_key'],
            'main_client_code' => (string) $this->config['main_client_code'],
        ];
    }

    private function guardConfigured(): void
    {
        foreach (self::CREDENTIAL_KEYS as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw NawrisIsNotConfigured::make();
            }
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/').'/';
    }

    /**
     * The credentials never reach the log.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrub(array $payload): array
    {
        foreach (self::CREDENTIAL_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, string $endpoint, array $context): void
    {
        Log::channel((string) ($this->config['log_channel'] ?? 'stack'))
            ->info("nawris.{$event} {$endpoint}", $context);
    }
}
