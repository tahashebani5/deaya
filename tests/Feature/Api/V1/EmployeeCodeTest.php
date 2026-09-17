<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every employee carries a short code the shop floor can say out loud — it is what a colleague
 * reads off the home screen when asking "who took this order?".
 *
 * The code is assigned by the server, never sent by the client, and never reused.
 *
 * Arrange - Act - Assert throughout.
 */
class EmployeeCodeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken];
    }

    public function test_a_new_account_is_given_an_employee_code(): void
    {
        // Arrange
        $payload = [
            'name' => 'عبدالوهاب فرحات',
            'email' => 'abdelwahab@printing.ly',
            'phone' => '0911234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        // Act
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Assert
        $response->assertCreated();
        $this->assertNotNull($response->json('data.user.employee_code'));
        $this->assertMatchesRegularExpression('/^\d{4,}$/', $response->json('data.user.employee_code'));
    }

    public function test_every_employee_gets_a_different_code(): void
    {
        // Arrange
        $count = 5;

        // Act
        $codes = User::factory()->count($count)->create()->pluck('employee_code');

        // Assert
        $this->assertCount($count, $codes->filter()->unique());
    }

    public function test_a_code_is_never_reused_after_the_employee_is_deleted(): void
    {
        // Arrange
        $first = User::factory()->create();
        $first->delete();

        // Act
        $second = User::factory()->create();

        // Assert
        $this->assertNotSame($first->employee_code, $second->employee_code);
    }

    public function test_the_signed_in_employee_reads_their_own_code(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->getJson('/api/v1/auth/me', $this->bearerFor($user));

        // Assert
        $response->assertOk()->assertJsonPath('data.employee_code', $user->employee_code);
    }

    public function test_the_client_cannot_choose_its_own_code(): void
    {
        // Arrange
        $payload = [
            'name' => 'موظف',
            'email' => 'staff@printing.ly',
            'phone' => '0921234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'employee_code' => '0001',
        ];

        // Act
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Assert — the server's own value wins; a request can never pick an identifier.
        $response->assertCreated();
        $this->assertNotSame('0001', $response->json('data.user.employee_code'));
    }
}
