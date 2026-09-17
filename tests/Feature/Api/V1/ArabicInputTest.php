<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Application\Api\V1\Middleware\DeshapeArabicInput;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\ArabicText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Arabic keyed already-shaped is folded on the way in, before anything stores it.
 *
 * **Why this is a middleware and not a rule on one field.** «ﺷﺮﻛﺔ ﺑﺮﻳﻤﻮﻻ» reads exactly like
 * «شركة بريمولا» on every screen it passes, so nobody can be asked to notice it — not the clerk
 * typing, not the reviewer reading. It arrives from whichever field happens to be typed on that
 * keyboard, which is every field. One door, applied once, is the only version of this that stays
 * true after the next endpoint is written. See {@see ArabicText}.
 *
 * Arrange - Act - Assert throughout.
 */
class ArabicInputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $user = User::factory()->create();

        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_a_name_typed_in_presentation_forms_is_stored_as_letters(): void
    {
        // Arrange — «شركة بريمولا» as a Windows keyboard can send it: pre-joined codepoints from
        // the Arabic Presentation Forms block, indistinguishable on screen from the real thing.
        $shaped = "\u{FEB7}\u{FEAE}\u{FEDC}\u{FE94} \u{FE91}\u{FEAE}\u{FEF3}\u{FEE4}\u{FEEE}\u{FEDF}\u{FE8E}";

        // Act
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/customers', [
            'name' => $shaped,
            'phone' => '0916667646',
        ]);

        // Assert — the letters, not the shapes, so the invoice can draw them and a search can
        // find them.
        $response->assertCreated();

        $customer = Customer::query()->latest('id')->firstOrFail();

        $this->assertSame('شركة بريمولا', $customer->name);
    }

    public function test_ordinary_arabic_passes_through_exactly_as_it_was_typed(): void
    {
        // Arrange — the common case, which must not be touched by any of this.
        $name = 'مخبز النخيل — فرع قرجي';

        // Act
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/customers', [
            'name' => $name,
            'phone' => '0912345678',
        ]);

        // Assert
        $response->assertCreated();

        $this->assertSame($name, Customer::query()->latest('id')->firstOrFail()->name);
    }

    public function test_a_password_is_never_folded(): void
    {
        // Arrange — a secret is bytes, not words. Folding one on the way in would change what
        // the user typed, and the hash it was first stored under would never match again.
        $secret = "sesame\u{FEF1}";

        $request = Request::create('/api/v1/customers', 'POST', [
            'name' => "\u{FEF1}",
            'password' => $secret,
            'password_confirmation' => $secret,
        ]);

        // Act
        (new DeshapeArabicInput)->handle($request, fn (Request $passed): Response => new Response);

        // Assert
        $this->assertSame('ي', $request->input('name'));
        $this->assertSame($secret, $request->input('password'));
        $this->assertSame($secret, $request->input('password_confirmation'));
    }

    public function test_it_reaches_a_value_nested_in_the_payload(): void
    {
        // Arrange — order lines, shop addresses and comment bodies all arrive nested, and the
        // shaped characters are no less likely there than at the top level.
        $request = Request::create('/api/v1/orders', 'POST', [
            'items' => [['notes' => "\u{FEB7}\u{FEAE}\u{FEDC}\u{FE94}"]],
        ]);

        // Act
        (new DeshapeArabicInput)->handle($request, fn (Request $passed): Response => new Response);

        // Assert
        $this->assertSame('شركة', $request->input('items.0.notes'));
    }
}
