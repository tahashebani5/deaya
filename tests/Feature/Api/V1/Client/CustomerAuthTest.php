<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The customer app's front door.
 *
 * **The two tests that matter most here are not the happy paths** — they are
 * `test_a_customer_token_is_refused_by_the_staff_api` and its mirror. A customer holds a Sanctum
 * token exactly as an employee does, and the only thing keeping the two apart is that each guard
 * names a provider. Sanctum registers `auth.guards.sanctum` with `provider => null` in its own
 * service provider, and `Guard::hasValidProvider()` answers `true` for a null provider — so
 * before this feature the staff guard accepted a token from *any* tokenable model. Pinning it to
 * `users` in config/auth.php is what those two tests are watching, and deleting that one line
 * hands every customer `GET /v1/home/summary`, which carries no `can:` at all.
 *
 * Arrange - Act - Assert throughout, and every request signs in with a real personal access
 * token rather than `actingAs` — the same reasoning `AuthTest` records.
 */
class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function bearerFor(Customer $customer, string $device = 'test-device'): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken($device)->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function staffBearer(): array
    {
        return ['Authorization' => 'Bearer '.User::factory()->create()->createToken('staff')->plainTextToken];
    }

    private function registered(string $phone = '0911111111', string $password = 'password123'): Customer
    {
        return Customer::factory()->create([
            'phone' => $phone,
            'password' => Hash::make($password),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'متجر النور',
            'phone' => '0913334444',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    // ───────────────────────────── register ─────────────────────────────

    public function test_register_creates_the_customer_and_returns_a_usable_token(): void
    {
        // Arrange
        $payload = $this->validRegistration();

        // Act
        $response = $this->postJson('/api/v1/client/auth/register', $payload);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.customer.name', 'متجر النور')
            ->assertJsonPath('data.customer.phone', '0913334444');

        $this->assertDatabaseHas('customers', ['phone' => '0913334444', 'name' => 'متجر النور']);

        $token = $response->json('data.token');
        $this->assertIsString($token);

        $me = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/client/auth/me');

        $me->assertOk()->assertJsonPath('data.phone', '0913334444');
    }

    /**
     * The code is server-assigned, exactly as it is when a clerk creates the customer.
     */
    public function test_register_allocates_a_customer_code_the_client_cannot_choose(): void
    {
        // Act
        $response = $this->postJson('/api/v1/client/auth/register', $this->validRegistration([
            'code' => 'C999',
        ]));

        // Assert
        $response->assertCreated();
        $this->assertNotSame('C999', $response->json('data.customer.code'));
        $this->assertDatabaseMissing('customers', ['code' => 'C999']);
    }

    public function test_register_refuses_a_phone_that_already_belongs_to_a_customer(): void
    {
        // Arrange
        $this->registered('0915556666');

        // Act
        $response = $this->postJson('/api/v1/client/auth/register', $this->validRegistration([
            'phone' => '0915556666',
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidRegistrations')]
    public function test_register_validates_its_input(array $payload, string $field): void
    {
        // Act
        $response = $this->postJson('/api/v1/client/auth/register', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRegistrations(): array
    {
        $base = [
            'name' => 'متجر النور',
            'phone' => '0913334444',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        return [
            'no name' => [array_merge($base, ['name' => '']), 'name'],
            'no phone' => [array_merge($base, ['phone' => '']), 'phone'],
            'phone is not Libyan' => [array_merge($base, ['phone' => '12345']), 'phone'],
            'no password' => [array_merge($base, ['password' => '']), 'password'],
            'password too short' => [array_merge($base, ['password' => 'abc', 'password_confirmation' => 'abc']), 'password'],
            'password not confirmed' => [array_merge($base, ['password_confirmation' => 'different']), 'password'],
        ];
    }

    // ───────────────────────────── login ─────────────────────────────

    public function test_login_with_the_phone_and_password_returns_a_token(): void
    {
        // Arrange
        $customer = $this->registered('0917778888', 'correct-horse');

        // Act
        $response = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0917778888',
            'password' => 'correct-horse',
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->assertIsString($response->json('data.token'));
    }

    public function test_login_refuses_a_wrong_password_against_the_phone_field(): void
    {
        // Arrange
        $this->registered('0917778888', 'correct-horse');

        // Act
        $response = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0917778888',
            'password' => 'wrong',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors('phone');
    }

    /**
     * A customer nobody registered and one who typed the wrong password get the identical
     * answer — telling them apart is how somebody discovers which numbers are customers.
     */
    public function test_login_answers_an_unknown_phone_exactly_as_a_wrong_password(): void
    {
        // Arrange
        $this->registered('0917778888', 'correct-horse');

        // Act
        $unknown = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0910000000',
            'password' => 'correct-horse',
        ]);
        $wrongPassword = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0917778888',
            'password' => 'wrong',
        ]);

        // Assert
        $this->assertSame($wrongPassword->status(), $unknown->status());
        $this->assertSame($wrongPassword->json('message'), $unknown->json('message'));
    }

    /**
     * A customer typed in by a clerk has no password at all. That must read as "wrong
     * credentials", never as a way in.
     */
    public function test_a_customer_without_a_password_cannot_log_in(): void
    {
        // Arrange
        Customer::factory()->create(['phone' => '0919990000', 'password' => null]);

        // Act
        $response = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0919990000',
            'password' => 'anything',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_deactivated_customer_is_told_why_only_after_the_password_checks_out(): void
    {
        // Arrange
        $customer = $this->registered('0918889999', 'correct-horse');
        $customer->update(['is_active' => false]);

        // Act
        $response = $this->postJson('/api/v1/client/auth/login', [
            'phone' => '0918889999',
            'password' => 'correct-horse',
        ]);

        // Assert
        $response->assertStatus(403)->assertJsonPath('status', false);
        $this->assertStringNotContainsString('غير صحيحة', (string) $response->json('message'));
    }

    // ───────────────────────────── the wall between the two apps ─────────────────────────────

    /**
     * **The line this whole design rests on.** `home/summary` is deliberately the one staff
     * route with no `can:` beside it, so a permission check cannot be what stops a customer —
     * only the guard's provider can.
     */
    public function test_a_customer_token_is_refused_by_the_staff_api(): void
    {
        // Arrange
        $headers = $this->bearerFor($this->registered());

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_staff_token_is_refused_by_the_customer_api(): void
    {
        // Arrange
        $headers = $this->staffBearer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertUnauthorized();
    }

    // ───────────────────────────── the session ─────────────────────────────

    public function test_me_needs_a_token(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_me_never_returns_the_password(): void
    {
        // Arrange
        $headers = $this->bearerFor($this->registered());

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertOk();
        $this->assertArrayNotHasKey('password', (array) $response->json('data'));
    }

    public function test_me_carries_the_shop_and_the_trade_it_is_in(): void
    {
        // Arrange — «متجر النور · بنغازي» and «ملابس وأحذية» are what «حسابي» draws under the
        // name, and they live two relations away from the account.
        $customer = $this->registered();
        $city = City::factory()->create(['name' => 'بنغازي']);
        $field = BusinessField::factory()->create(['name' => 'ملابس وأحذية']);

        CustomerShop::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'متجر النور',
            'city_id' => $city->id,
            'business_field_id' => $field->id,
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($customer))->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.shop.name', 'متجر النور')
            ->assertJsonPath('data.shop.city_name', 'بنغازي')
            ->assertJsonPath('data.shop.business_field', 'ملابس وأحذية');
    }

    public function test_an_account_with_no_shop_is_sent_a_null_rather_than_nothing(): void
    {
        // Arrange — a customer registered from the app has no shop until staff add one. The key
        // must still be there, so the screen draws a name with no line under it rather than
        // treating the account as half-loaded.
        $customer = $this->registered();

        // Act
        $response = $this->withHeaders($this->bearerFor($customer))->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertOk()->assertJsonPath('data.shop', null);
    }

    public function test_the_shop_never_carries_an_id_or_a_map_pin(): void
    {
        // Arrange — the customer app cannot edit a shop and has no map. An id here would be a
        // handle on something nothing can be done with, and coordinates are where somebody
        // lives.
        $customer = $this->registered();
        CustomerShop::factory()->create(['customer_id' => $customer->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($customer))->getJson('/api/v1/client/auth/me');

        // Assert
        $shop = (array) $response->assertOk()->json('data.shop');

        $this->assertSame(['name', 'city_name', 'business_field'], array_keys($shop));
    }

    public function test_signing_in_does_not_pay_for_the_shop_nobody_is_looking_at(): void
    {
        // Arrange — `whenLoaded`: register and login answer at a moment when «حسابي» is not on
        // screen, and three relations loaded to fill a card nobody can see is a query the
        // sign-in screen pays for.
        $customer = $this->registered();

        // Act
        $response = $this->postJson('/api/v1/client/auth/login', [
            'phone' => $customer->phone,
            'password' => 'password123',
        ]);

        // Assert
        $response->assertOk();
        $this->assertArrayNotHasKey('shop', (array) $response->json('data.customer'));
    }

    public function test_logout_revokes_only_the_token_that_made_the_request(): void
    {
        // Arrange
        $customer = $this->registered();
        $keep = $customer->createToken('other-device')->plainTextToken;
        $headers = $this->bearerFor($customer);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/client/auth/logout');

        // Assert
        $response->assertOk();

        $this->withHeaders($headers)->getJson('/api/v1/client/auth/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => "Bearer {$keep}"])
            ->getJson('/api/v1/client/auth/me')->assertOk();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        // Arrange
        $customer = $this->registered();
        $other = $customer->createToken('other-device')->plainTextToken;
        $headers = $this->bearerFor($customer);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/client/auth/logout-all');

        // Assert
        $response->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$other}"])
            ->getJson('/api/v1/client/auth/me')->assertUnauthorized();
    }

    /**
     * The global scope on the model is what does this, and it is worth a test of its own: a
     * customer removed from the system must stop authenticating the moment the row is trashed,
     * without a single line checking for it.
     */
    public function test_a_soft_deleted_customer_stops_authenticating(): void
    {
        // Arrange
        $customer = $this->registered();
        $headers = $this->bearerFor($customer);
        $customer->delete();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/client/auth/me');

        // Assert
        $response->assertUnauthorized();
    }
}
