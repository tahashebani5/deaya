<?php

declare(strict_types=1);

namespace Tests\Feature\Carrier;

use App\Domain\Carrier\Exceptions\NawrisIsNotConfigured;
use App\Domain\Carrier\Exceptions\NawrisRejectedRequest;
use App\Domain\Carrier\Exceptions\NawrisRequestFailed;
use App\Domain\Carrier\Support\NawrisClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The carrier client, and the four ways their envelope is not what it looks like.
 *
 * **Every one of these is a real quirk from the contract, and every one of them is a bug if the
 * client gets it wrong.** The first is the dangerous one: a logical failure arrives with HTTP
 * 200, so a client that trusted the status line would report success and let a parcel row be
 * written for a shipment that does not exist.
 *
 * `Http::fake()` throughout — **no test may reach the carrier**, and there is no sandbox to reach
 * even if one were allowed.
 *
 * Arrange - Act - Assert throughout.
 */
class NawrisClientTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): NawrisClient
    {
        return new NawrisClient(array_merge([
            'base_url' => 'https://carrier.test/external-api/',
            'authentication_key' => 'key',
            'main_client_code' => 'client',
            'log_channel' => 'null',
            'timeout' => 5,
            'connect_timeout' => 2,
        ], $overrides));
    }

    // ── quirk 1: failures arrive as HTTP 200 ─────────────────────────────────────────────

    public function test_a_logical_failure_with_a_200_is_still_a_failure(): void
    {
        // Arrange — the quirk that matters most. Checking the status code alone would make this
        // look like a successful dispatch.
        Http::fake(['*' => Http::response(['success' => 0, 'error_msg' => 'رقم الهاتف غير صالح'], 200)]);

        // Assert
        $this->expectException(NawrisRejectedRequest::class);

        // Act
        $this->client()->addOrder(['receiver' => '1']);
    }

    public function test_their_reason_is_carried_through_rather_than_swallowed(): void
    {
        // Arrange — «فشل الطلب» gives support nothing. The whole `errors` object is worth keeping.
        Http::fake(['*' => Http::response([
            'success' => 0,
            'error_msg' => 'بيانات ناقصة',
            'errors' => ['phone1' => ['مطلوب']],
        ], 200)]);

        // Act
        $thrown = null;

        try {
            $this->client()->addOrder(['receiver' => '1']);
        } catch (NawrisRejectedRequest $e) {
            $thrown = $e;
        }

        // Assert
        $this->assertNotNull($thrown);
        $this->assertStringContainsString('بيانات ناقصة', $thrown->getMessage());
        $this->assertStringContainsString('phone1', $thrown->getMessage());
    }

    // ── quirk 2: the payload key varies ──────────────────────────────────────────────────

    public function test_a_payload_under_result_is_read(): void
    {
        // Arrange
        Http::fake(['*' => Http::response(['success' => 1, 'result' => ['code' => '3702994', 'bar_code' => 'B1']], 200)]);

        // Act
        $response = $this->client()->addOrder(['receiver' => '1']);

        // Assert
        $this->assertSame('3702994', $response->code());
        $this->assertSame('B1', $response->barCode());
    }

    public function test_a_payload_under_feed_is_read_the_same_way(): void
    {
        // Arrange — the same answer under a different key, which is how some of their endpoints
        // reply. A client that knew only `result` would silently return nothing.
        Http::fake(['*' => Http::response(['success' => 1, 'feed' => ['code' => '99']], 200)]);

        // Act
        $response = $this->client()->searchOrder('99');

        // Assert
        $this->assertSame('99', $response->code());
    }

    public function test_an_identifier_that_arrives_as_a_number_is_read_as_a_string(): void
    {
        // Arrange — a parcel keyed on 3702994 and one keyed on '3702994' are the same parcel, and
        // a webhook lookup that disagreed about the type would never match.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => ['code' => 3702994]], 200)]);

        // Act
        $response = $this->client()->addOrder(['receiver' => '1']);

        // Assert
        $this->assertSame('3702994', $response->code());
    }

    // ── quirk 3: delete and cancel use another envelope ──────────────────────────────────

    public function test_cancel_succeeds_on_success_one_with_no_payload_at_all(): void
    {
        // Arrange
        Http::fake(['*' => Http::response(['success' => 1], 200)]);

        // Act
        $response = $this->client()->cancelOrder('3702994');

        // Assert — no payload is the correct answer here, not a failure to parse one.
        $this->assertNull($response->code());
    }

    public function test_delete_fails_when_success_is_not_one(): void
    {
        // Arrange
        Http::fake(['*' => Http::response(['success' => 0, 'error_msg' => 'الشحنة تحركت'], 200)]);

        // Assert
        $this->expectException(NawrisRejectedRequest::class);

        // Act
        $this->client()->deleteOrder('3702994');
    }

    // ── quirk 4: credentials are body fields ─────────────────────────────────────────────

    public function test_the_credentials_travel_in_the_body_of_every_request(): void
    {
        // Arrange — their API, not a choice of ours. A client that sent headers would be refused
        // by everything.
        Http::fake(['*' => Http::response(['success' => 1, 'result' => ['code' => '1']], 200)]);

        // Act
        $this->client()->addOrder(['receiver' => '19505']);

        // Assert
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['authentication_key'] ?? null) === 'key'
                && ($body['main_client_code'] ?? null) === 'client'
                && ($body['receiver'] ?? null) === '19505';
        });
    }

    // ── transport, and configuration ─────────────────────────────────────────────────────

    public function test_a_transport_failure_is_a_different_exception_from_a_rejection(): void
    {
        // Arrange — worth retrying, unlike a rejection, which is why the two are separate types.
        Http::fake(['*' => Http::response('gateway down', 502)]);

        // Assert
        $this->expectException(NawrisRequestFailed::class);

        // Act
        $this->client()->addOrder(['receiver' => '1']);
    }

    public function test_a_transport_failure_answers_502_rather_than_422(): void
    {
        // Arrange
        Http::fake(['*' => Http::response('nope', 500)]);

        // Act
        $thrown = null;

        try {
            $this->client()->addOrder(['receiver' => '1']);
        } catch (NawrisRequestFailed $e) {
            $thrown = $e;
        }

        // Assert — infrastructure, not a broken business rule.
        $this->assertNotNull($thrown);
        $this->assertSame(502, $thrown->httpStatus());
    }

    public function test_missing_credentials_are_refused_before_any_call_is_made(): void
    {
        // Arrange — a deployment mistake must not become a carrier error message that reads as
        // though the parcel were at fault.
        Http::fake();

        // Assert
        $this->expectException(NawrisIsNotConfigured::class);

        // Act
        $this->client(['authentication_key' => ''])->addOrder(['receiver' => '1']);
    }

    public function test_nothing_is_sent_when_the_credentials_are_missing(): void
    {
        // Arrange
        Http::fake();

        // Act
        try {
            $this->client(['main_client_code' => ''])->addOrder(['receiver' => '1']);
        } catch (NawrisIsNotConfigured) {
            // The assertion is below; the throw itself is covered by the test above.
        }

        // Assert
        Http::assertNothingSent();
    }

    // ── the lookups ──────────────────────────────────────────────────────────────────────

    public function test_the_geography_lookups_return_their_rows(): void
    {
        // Arrange
        Http::fake(['*' => Http::response([
            'result' => [['id' => 5, 'name' => 'طرابلس'], ['id' => 6, 'name' => 'بنغازي']],
        ], 200)]);

        // Act
        $governments = $this->client()->governments();

        // Assert
        $this->assertCount(2, $governments);
        $this->assertSame('طرابلس', $governments[0]['name']);
    }

    public function test_an_empty_payload_is_an_empty_list_rather_than_an_error(): void
    {
        // Arrange — "accept either key, default to empty" is the contract's own advice.
        Http::fake(['*' => Http::response(['success' => 1], 200)]);

        // Act
        $areas = $this->client()->areas('5');

        // Assert
        $this->assertSame([], $areas);
    }
}
