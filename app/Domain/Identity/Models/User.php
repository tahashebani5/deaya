<?php

namespace App\Domain\Identity\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Identity\Actions\AllocateEmployeeCode;
use App\Domain\Identity\Enums\RoleName;
use App\Providers\AppServiceProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Permission\Traits\HasRoles;

// Models live under app/Domain/, so Laravel's App\Models convention can no longer
// guess the factory. Every domain model names its factory explicitly.
#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'email', 'phone', 'password', 'salary', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasAuditTrail
{
    /** @use HasFactory<UserFactory> */
    use Auditable, CausesActivity, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * A user is soft deleted, and that is what makes deleting one safe.
     *
     * Laravel's user provider and Sanctum both resolve through this model, so the global
     * `deleted_at is null` scope applies to logging in and to every token lookup: a deleted
     * account stops authenticating immediately, without a single line of code checking for it.
     * The row survives, so the accounts they touched still name who touched them.
     *
     * Their password never reaches the audit trail —
     * `activitylog.default_except_attributes` strips it for every model.
     */

    /**
     * Roles and permissions are stored against the `web` guard.
     *
     * Requests authenticate through Sanctum, but Sanctum is a token *transport* — it resolves to
     * the same `users` provider. Pinning the guard here stops Spatie from inferring `sanctum`
     * and then failing to match roles that were created under `web`, which is the usual first
     * bug when the two are combined.
     */
    protected string $guard_name = 'web';

    /**
     * An account starts usable.
     *
     * Stated here as well as on the column, because the two are read at different moments: the
     * database default fills the row, and this fills the *model* — which, having just been
     * inserted, has never read the row back. Without it, the response to creating an account
     * carries `is_active: null` and the app draws «موقوف» on somebody nobody stopped.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * Every account is stamped with an employee code as it is created.
     *
     * Here rather than in `AuthService::register`, because "an employee has a code" is an
     * invariant of the model, not a step in one way of creating one. Registration, a seeder and
     * a factory all insert users; hanging the rule off the only path that happens to go through
     * the service would leave the other two writing rows the home screen cannot label — and the
     * unique index would not catch it, since PostgreSQL lets nulls repeat.
     *
     * `??=` so a caller that already allocated one (a data import, say) keeps it. The column is
     * absent from {@see Fillable} on purpose: a request can never choose its own identifier.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->employee_code ??= app(AllocateEmployeeCode::class)();
        });
    }

    /**
     * Whether this user is an administrator — full access to everything, always.
     *
     * @see AppServiceProvider::boot() for the gate that enforces it.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(RoleName::Admin->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // A string, not a float: a wage is money, and this schema counts money in decimals
            // everywhere for the reason `OrderPayment` spells out.
            'salary' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
