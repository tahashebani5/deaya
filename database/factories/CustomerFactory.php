<?php

namespace Database\Factories;

use App\Domain\Customer\Actions\AllocateCustomerIdentifier;
use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /** @var class-string<Customer> */
    protected $model = Customer::class;

    /** Guarantees distinct phone numbers, which the customers table now requires. */
    private static int $phoneSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            // A counter rather than a random number: phones are unique in the database, and a
            // random 8-digit tail would collide eventually and fail an unrelated test.
            'phone' => '09'.str_pad((string) (++self::$phoneSequence), 8, '0', STR_PAD_LEFT),
            'is_active' => true,
            // Null by default, because that is what nearly every real row holds: a customer only
            // gets a password by registering in the app, and the hundreds imported from the
            // customer book never will. A factory that defaulted to a usable password would let
            // a login test pass without anyone ever having registered.
            'password' => null,
        ];
    }

    /** A customer who has installed the app and can sign in. */
    public function registered(string $password = 'password123'): static
    {
        return $this->state(fn () => ['password' => $password]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * The code is normally allocated by CreateCustomer, which the factory bypasses — so it
     * reserves an id the same way, keeping factory-made customers consistent with real ones.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Customer $customer): void {
            if ($customer->code === null) {
                $identifier = app(AllocateCustomerIdentifier::class)();
                $customer->id = $identifier->id;
                $customer->code = $identifier->code;
            }
        });
    }
}
