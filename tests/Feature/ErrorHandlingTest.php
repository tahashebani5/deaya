<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Customer\Actions\SyncCustomerShops;
use App\Domain\Customer\DTOs\CustomerShopData;
use App\Domain\Customer\Exceptions\ShopDoesNotBelongToCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Identity\AuthService;
use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ProvidesApiFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the error-handling pattern itself: domain code throws typed failures, one boundary
 * renders them, and nothing in app/ catches anything.
 *
 * Arrange - Act - Assert throughout.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_contains_no_try_catch_at_all(): void
    {
        // Arrange
        $appPath = app_path();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath));
        $offenders = [];

        // Act
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/\btry\s*\{|\bcatch\s*\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace($appPath.'/', '', $file->getPathname());
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            'Business code must not catch exceptions — throw a DomainException and let the '.
            'boundary in bootstrap/app.php render it. Offending files: '.implode(', ', $offenders),
        );
    }

    public function test_a_domain_exception_declares_its_own_api_shape(): void
    {
        // Arrange
        $exception = InvalidCredentials::make();

        // Act & Assert — the contract the single boundary relies on.
        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertInstanceOf(ProvidesApiFailure::class, $exception);
        $this->assertSame(422, $exception->httpStatus());
        $this->assertSame('بيانات الدخول غير صحيحة', $exception->userMessage());
        $this->assertSame(['login' => ['بيانات الدخول غير صحيحة']], $exception->fieldErrors());
    }

    public function test_domain_exceptions_stay_out_of_the_error_log(): void
    {
        // Arrange
        $exception = InvalidCredentials::make();

        // Act & Assert — an expected business failure is normal traffic, not a fault.
        $this->assertInstanceOf(ShouldntReport::class, $exception);
    }

    public function test_a_domain_exception_thrown_anywhere_is_rendered_as_the_envelope(): void
    {
        // Arrange — a throwaway route proves the boundary handles *any* ProvidesApiFailure,
        // not just the exceptions the real endpoints happen to raise.
        Route::middleware('api')->get('/api/v1/testing/boom', function (): never {
            throw ShopDoesNotBelongToCustomer::make(7, 3);
        });

        // Act
        $response = $this->getJson('/api/v1/testing/boom');

        // Assert
        $response->assertStatus(422)
            ->assertExactJson([
                'status' => false,
                'message' => 'المحل رقم 7 لا ينتمي للعميل رقم 3',
                'data' => null,
            ]);
    }

    public function test_a_domain_exception_with_field_errors_renders_them(): void
    {
        // Arrange
        Route::middleware('api')->get('/api/v1/testing/credentials', function (): never {
            throw InvalidCredentials::make();
        });

        // Act
        $response = $this->getJson('/api/v1/testing/credentials');

        // Assert
        $response->assertStatus(422)
            ->assertJson([
                'status' => false,
                'data' => null,
                'errors' => ['login' => ['بيانات الدخول غير صحيحة']],
            ]);
    }

    public function test_login_reports_invalid_credentials_through_the_domain_exception(): void
    {
        // Arrange
        User::factory()->create(['email' => 'user@printing.ly']);
        $service = app(AuthService::class);

        // Assert (expectation set before the Act, as PHPUnit requires)
        $this->expectException(InvalidCredentials::class);

        // Act
        $service->login('user@printing.ly', 'wrong-password');
    }

    public function test_syncing_a_shop_from_another_customer_throws_rather_than_duplicating_it(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $foreignShop = CustomerShop::factory()->create();
        $sync = app(SyncCustomerShops::class);
        $payload = [new CustomerShopData(name: 'x', cityId: $foreignShop->city_id, id: $foreignShop->id)];

        // Assert
        $this->expectException(ShopDoesNotBelongToCustomer::class);

        // Act
        $sync($customer, $payload);
    }

    public function test_an_unexpected_failure_becomes_a_generic_500_envelope(): void
    {
        // Arrange — a real bug, not a business rule: it must not leak internals to the client.
        config()->set('app.debug', false);
        Route::middleware('api')->get('/api/v1/testing/bug', function (): never {
            throw new \RuntimeException('internal detail that must not leak');
        });

        // Act
        $response = $this->getJson('/api/v1/testing/bug');

        // Assert
        $response->assertStatus(500)
            ->assertExactJson([
                'status' => false,
                'message' => 'حدث خطأ ما',
                'data' => null,
            ]);
    }
}
