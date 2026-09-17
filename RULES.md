# Printing — Backend Rules

> The binding standard for **how** we build the Printing API.
> **Clean code · Clean structure · Deliberate decisions · Every endpoint tested with real use cases.**
>
> [CLAUDE.md](CLAUDE.md) holds the general engineering rules that apply to any repository.
> **This file holds the ones specific to this backend, and wins where the two overlap.**
> Everything below is relative to `backend/`.

---

## 0. The six rules that matter most

If you remember nothing else:

1. **Test first, and in Arrange-Act-Assert.** No behaviour ships without a test written the same change. → [§6](#6-testing)
2. **Throw, never catch.** There is no `try`/`catch` in `app/`, and a test enforces it. → [§5](#5-error-handling)
3. **Organise by domain, not by file type.** Business logic in `Domain/`, HTTP in `Application/`. → [§3](#3-architecture)
4. **Never hand-write API docs.** If the spec is wrong, fix the *code*. → [§7](#7-api-spec)
5. **Migrations are forward-only.** Fix a mistake with a new migration, never by editing an applied one. → [§8](#8-database)
6. **Every model soft deletes and keeps an audit trail.** A test fails the build if one does not. → [§10](#10-soft-deletes-and-the-audit-trail)

---

## 1. Production safety

**Detecting production:** the environment is production when `APP_ENV=production`, **or** `APP_URL`
points at the live host. Set the production host here once it exists → **`<PROD_HOST>` (fill in)**.

When the environment is production:

1. ❌ **Never** run the test suite — not even with `--filter`. The suite truncates tables.
2. ❌ **Never** run anything that writes: `db:seed`, `migrate:fresh`, `migrate:refresh`,
   `migrate:rollback`, or `tinker` writes.
3. ❌ **Never** run a destructive or schema-dropping migration.
4. ✅ Read-only artisan is fine: `route:list`, `config:show`, `about`, `queue:work`, log tailing.
5. ✅ Editing source files is fine — just never *execute* the above against production.

---

## 2. Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 on PHP 8.3+ |
| Database | **PostgreSQL** (dev `printing_bags`, tests `printing_bags_test`) |
| API auth | **Sanctum** personal access tokens (Bearer) |
| API spec | **Scramble** → OpenAPI 3.1, generated from code |
| Tests | **PHPUnit** (not Pest — a deliberate choice, keep it) |
| Style | **Laravel Pint**, the single source of style truth |
| Tooling | Laravel Boost (MCP), Pail, Tinker |

Adopt only when a real requirement calls for it: `spatie/laravel-permission` (RBAC), Filament
(admin UI), Redis + Horizon (queues at scale), Reverb (websockets). **Every dependency is a
decision — justify it.**

### Commands

```bash
php artisan serve                 # http://localhost:8000
php artisan test                  # whole suite  ·  --filter=CustomerTest for one
./vendor/bin/pint                 # fix style    ·  --test to check only
php artisan migrate               # never migrate:fresh on anything shared
composer spec                     # export OpenAPI to ../Docs/openapi.json
php artisan scramble:analyze      # verify the spec generates cleanly
```

---

## 3. Architecture

**Organised by domain, not by file type.** A type-first layout (`app/Services/`, `app/DTOs/`) turns
into a drawer of unrelated files once the model count grows, and every feature edit touches six
distant folders.

```
app/
├── Domain/                     business logic — no Request, no Response, no HTTP
│   ├── <Context>/
│   │   ├── Models/
│   │   ├── Actions/            one verb, one class
│   │   ├── DTOs/               readonly, typed
│   │   ├── Queries/            reusable reads
│   │   ├── Exceptions/         this context's failures
│   │   └── <Context>Service.php   the module's ONLY public entry point
│   └── ...
├── Application/                transport only
│   ├── Api/V1/{Controllers,Requests,Resources}
│   └── Controller.php
└── Support/                    ApiEnvelope, ResponseTrait, base exceptions
```

Existing contexts: `Identity` (users, roles, auth), `Customer` (customers, shops), `Catalog`
(products, sizes, prices, photos), `Inventory` (warehouses, **stock items**, balances, the ledger,
cost layers), `Order`, `PurchaseOrder`, `Vendor`, `Delivery` (cities, regions), `Reporting` and
`Audit` (the trail every other context writes to without asking).

### The rules

- **Dependencies run one way.** `Order` may depend on `Catalog`; `Catalog` must never import
  `Order`. When two contexts must react to each other, use a domain event — never a back-reference.
- **Cross-context access goes through the Service.** Another context calls `CustomerService`; it
  never touches `Customer::query()`. That seam is what lets a context change internally without a
  ripple. *Inside* a context, work lives in the Actions and Queries — the Service is the door, not
  a place for logic.
- **Controllers are thin.** Validate via FormRequest → call a Service/Action → return a Resource
  through the envelope. No business logic, no query building.
- **Actions over fat services.** One verb per class (`CreateCustomer`, `SyncCustomerShops`). A
  Service with forty methods is the old `app/Services/` drawer one level down.
- **DTOs at boundaries.** No associative arrays between layers. An array may cross into the domain
  exactly once, through a `fromArray()` on the DTO, fed by already-validated request data.
- **Resources shape every response.** Never return a raw model or `->toArray()`.
- **Enums for every status/type.** No magic strings; back state changes with explicit legal
  transitions.
- **Wrap multi-step writes in `DB::transaction`.**
- **Eager-load.** N+1 is a defect, not a style nit — and `Model::shouldBeStrict()` makes it throw
  outside production (see [§9](#9-conventions)).

### Models live outside `App\Models`

Laravel can no longer guess a model's factory. Every domain model names it, and every factory names
its model:

```php
#[UseFactory(CustomerFactory::class)]
class Customer extends Model { }

class CustomerFactory extends Factory {
    protected $model = Customer::class;
}
```

---

## 4. The response envelope

Every response — success **and** failure — has one shape, so a client parses one thing:

```json
{ "status": true, "message": "تم بنجاح", "data": {} }
```

A validation failure adds `errors` keyed by field. A paginated list adds a sibling `meta`
(`current_page`, `per_page`, `last_page`, `total`).

[`App\Support\ApiEnvelope`](app/Support/ApiEnvelope.php) is the **only** definition of that shape.
Controllers reach it through [`ResponseTrait`](app/Support/ResponseTrait.php)
(`success` · `created` · `successMessage` · `successWithPagination` · `error` ·
`validationErrorsResponse`); thrown exceptions reach it through the handlers in
[bootstrap/app.php](bootstrap/app.php). **Nothing else writes a response body.**

User-facing messages are **Arabic**.

---

## 5. Error handling

> **Throw, never catch.** There is **no `try`/`catch` anywhere in `app/`**, and
> `ErrorHandlingTest` walks the tree and fails the build if one appears.

Business code states what went wrong by throwing, then stops caring. Exactly one place turns a
failure into a response. That keeps error shaping in one file instead of scattered across every
service, and makes it impossible for one caller to swallow a failure another caller reports.

### Writing a new failure

Extend [`DomainException`](app/Support/Exceptions/DomainException.php). It defaults to **422** and
implements `ShouldntReport`, because an expected business failure is normal traffic, not a fault
worth logging:

```php
final class ShopDoesNotBelongToCustomer extends DomainException
{
    public static function make(int $shopId, int $customerId): self
    {
        return new self("المحل رقم {$shopId} لا ينتمي للعميل رقم {$customerId}");
    }
}
```

Override `httpStatus()`, `userMessage()` or `fieldErrors()` when the default is wrong. Field errors
render exactly like a validation failure, so a client can show them inline.

**No registration step.** The handler in `bootstrap/app.php` matches on the
[`ProvidesApiFailure`](app/Support/Exceptions/ProvidesApiFailure.php) *interface*, so a new failure
type is rendered correctly the moment it is written.

### What goes where

| Situation | What to do |
|---|---|
| A business rule is broken | `throw` a `DomainException` subclass |
| Input is malformed | Let the FormRequest reject it (422 automatically) |
| A genuine bug | Throw anything else — it is logged and becomes a generic 500 that never leaks its message outside local debugging |
| An infrastructure probe whose failure *is* the answer (e.g. "is the DB reachable?") | `rescue(fn () => ..., rescue: false, report: false)` — never a hand-written `try`/`catch` |

### Never

- ❌ `try`/`catch` in `app/` — the boundary already catches everything.
- ❌ Throwing Laravel's `ValidationException` from `Domain/` — that leaks an HTTP concern into the
  business layer. Throw a domain exception that carries `fieldErrors()` instead.
- ❌ Returning `null`/`false` to signal a failure a caller must interpret.

---

## 6. Testing

> **Every behaviour has a test. Change the behaviour and you change its test in the same edit,
> adding cases for what is new. A change without a test change is incomplete.**

### TDD

Write the failing test first, then the code that makes it pass. Red → green → refactor.

### AAA — Arrange, Act, Assert

Every test is visibly split into three. **Do not merge Act and Assert into one fluent chain** —
capture the result, then assert on it:

```php
public function test_update_changes_the_basic_fields(): void
{
    // Arrange
    $customer = Customer::factory()->create(['name' => 'قديم']);
    $headers = $this->auth();

    // Act
    $response = $this->withHeaders($headers)
        ->putJson("/api/v1/customers/{$customer->id}", ['name' => 'جديد', 'phone' => '0922222222']);

    // Assert
    $response->assertOk()->assertJsonPath('data.name', 'جديد');
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'جديد']);
}
```

Omit an empty Arrange rather than writing a hollow marker. Use a `DataProvider` for families of
validation cases instead of copy-pasting near-identical tests.

### Every endpoint's checklist

Cover both the cases the user described **and** the ones they didn't that will break in production:

- ✅ **Happy path** — correct 2xx and correct `data` shape.
- ✅ **Validation** — each required / typed / bounded field rejected with 422 + field errors.
- ✅ **Auth** — unauthenticated → 401.
- ✅ **Authorization** — authenticated but forbidden → 403.
- ✅ **Not found** — missing or foreign resource → 404.
- ✅ **Lists** — pagination, filtering, sorting, the empty set, and an absurd `per_page`.
- ✅ **Boundaries** — min/max, zero, negative, Arabic text, very long input.
- ✅ **Ownership** — a request naming another owner's record is refused, and that record is
  verified untouched.
- ✅ **Envelope** — assert `status` / `message` / `data`, not just the HTTP code.
- ✅ **Invariants** — server-assigned fields cannot be supplied by the client.

### How this suite works

- **Tests run against PostgreSQL** (`printing_bags_test`), not SQLite, so they exercise the
  production driver. `ilike`, decimal precision and constraint behaviour all differ.
- **`RefreshDatabase`** on every feature test.
- **Authenticate with a real token** (`$user->createToken(...)->plainTextToken`), not
  `Sanctum::actingAs` — that produces a `TransientToken` and skips the path the app actually uses.
- **The container is reused within one test**, so an auth guard keeps returning a user whose token
  you just deleted. Call `$this->app->get('auth')->forgetGuards()` before re-checking.
- **Factories must satisfy real constraints.** Sequence-generated values, not random ones, wherever
  a unique index exists — a chance collision failing an unrelated test is a miserable bug to find.
- A bug fix ships **with the regression test** that would have caught it.

---

## 7. API spec

Every endpoint is published as OpenAPI 3.1 automatically. **Never hand-write API documentation.**

- Interactive: **`/docs/api`** · raw: **`/docs/api.json`** · export: `composer spec`.
- Scramble reads routes, FormRequests, Resources, enums and return types. This is exactly *why*
  [§3](#3-architecture) matters: clean typed code produces a correct spec for free.
- Validation rules become real schema constraints — `between:-90,90` becomes `minimum`/`maximum`,
  `confirmed` adds the confirmation field, and a comment above a rule becomes its description.
- Security is derived from route middleware, so a new `auth:sanctum` route documents its own lock.
- **If the spec is wrong, fix the code** — the FormRequest, Resource or return type. Never a doc.
- Run **`php artisan scramble:analyze`** before considering an endpoint done.

> ⚠️ **`rules()` must be statically analysable.** Scramble reads the method without running it, so
> `array_merge(parent::rules(), [...])` yields **no request body at all** — the endpoint silently
> publishes as undocumented. Write the array out in full, even if it duplicates a parent. Call a
> private method for a single dynamic rule (see
> [UpdateCustomerRequest](app/Application/Api/V1/Requests/Customer/UpdateCustomerRequest.php)).

---

## 8. Database

- **Forward-only.** Never edit an applied migration — add a new one. A migration already pushed
  describes what it *did*; only later migrations describe the current shape.
- **Enforce invariants in the database, not only in validation.** A `unique` rule loses to two
  concurrent requests that both pass the existence check before either commits; a unique index does
  not. Do both: validation gives the readable 422, the index is the guarantee.
- **No data cleanup inside a schema migration.** If a constraint cannot be applied because the data
  violates it, the migration *should* fail — deciding which of two real records to keep is a
  business decision, not a silent side effect.
- **Rename indexes alongside columns.** PostgreSQL keeps an index's original name through a column
  rename, leaving a misleading name behind.
- **Money: never a float.** Integer minor units or `decimal`, in a value object.
- **Coordinates: `decimal(10,7)`**, cast to `float` on the model so the API emits numbers rather
  than `"32.8872000"`.
- **A nullable column with required validation** is the honest answer when existing rows have no
  correct value and none can be derived. Say so in the migration docblock, and tighten to NOT NULL
  in a follow-up once the old rows are filled.
- Verify a risky migration with **`php artisan migrate --pretend`** before running it.

---

## 9. Conventions

1. **PSR-12 via Pint.** Run `./vendor/bin/pint` before committing.
2. **Strict mode is on** outside production:
   `Model::shouldBeStrict(! $this->app->isProduction())`. Lazy loading, missing attributes and
   silently discarded attributes all throw. Do not disable it to make something pass — fix the
   cause.
3. **Type everything** — parameters, returns, properties. `declare(strict_types=1)` in `app/`.
4. **Server-assigned fields are never fillable.** Identifiers, codes and computed values are
   assigned directly, never mass-assigned, so a request can never supply them.
5. **Arabic** for user-facing strings and validation messages; **English** for code, comments and
   commit messages.
6. **Comments explain *why*, not *what*.** A comment restating the code is noise; one recording a
   decision or a trap is worth keeping.
7. **Never commit secrets.** `.env` is ignored; add every new key to `.env.example`.
8. **Small named units.** If a controller method outgrows the screen, extract an Action.
9. **Arabic is folded at the door.** `DeshapeArabicInput` runs beside `TrimStrings` on every
   request and rewrites the Arabic Presentation Forms block (`U+FE70`–`U+FEFC`) — «ﺷﺮﻛﺔ», the
   pre-joined codepoints some Windows keyboards and every copy-out-of-a-PDF produce — into the
   letters it stands for. The two are indistinguishable on screen and different bytes to every
   `LIKE`, `ORDER BY` and duplicate check; one such character stored in a customer's name is what
   stopped an invoice PDF from being drawn on the phone, because Arabic faces carry no glyphs for
   that block. Never fold a password, a path or a token — see `App\Support\ArabicText`, and
   `php artisan text:deshape --dry-run` for the rows that predate the middleware.

---

## 10. Soft deletes and the audit trail

> **Every model soft deletes and keeps an audit trail. Every record a user opens has a
> `/logs` endpoint. This is not optional, and
> [ModelConventionsTest](tests/Feature/Audit/ModelConventionsTest.php) fails the build when a new
> model skips it.**

Adding a model is three lines and one migration column:

```php
class Invoice extends Model implements HasAuditTrail   // only if it gets a /logs endpoint
{
    use Auditable, SoftDeletes;                        // Auditable brings the logging
}
```

1. `use Auditable` — [App\Domain\Audit\Concerns\Auditable](app/Domain/Audit/Concerns/Auditable.php).
   Logs every attribute, only what changed, with the Arabic sentence and the signed-in causer.
   No per-model configuration: a policy you have to remember to write gets forgotten on the model
   where it mattered. Secrets are stripped globally by `activitylog.default_except_attributes`.
2. `use SoftDeletes`, plus `$table->softDeletes()->index()` in the migration.
3. **Add a case to [AuditSubject](app/Domain/Audit/Enums/AuditSubject.php).** It is the
   application's morph map, so `activity_log.subject_type` reads `invoice` rather than a PHP
   class name — a published value that must not move when a file does.
4. If a user opens a screen for it, implement `HasAuditTrail` and add
   `GET /invoices/{invoice}/logs` with `can:logs.view`, using the
   [ReadsAuditTrail](app/Application/Api/V1/Controllers/Concerns/ReadsAuditTrail.php) trait.

### What soft deleting breaks, and how each is handled

Three things stop working the moment a table gains `deleted_at`. All three are already solved;
the point of listing them is that the *next* table needs the same three.

- **Unique indexes count deleted rows.** Deleting the city طرابلس and adding it back would fail
  against a row the API says does not exist. Every unique index is *partial*
  (`WHERE deleted_at IS NULL`) — see
  [the migration](database/migrations/2026_07_31_130300_make_unique_indexes_ignore_soft_deleted_rows.php).
  A new unique index is written the same way.
- **Validation has to agree with it.** `unique:` ignores trashed rows exactly as the model does,
  so every rule carries `->withoutTrashed()`. Without it the index allows what the 422 refuses.
- **`cascadeOnDelete` never fires.** Nothing is deleted, so the database's cascade is dead code.
  Children go through [CascadesSoftDeletes](app/Domain/Audit/Concerns/CascadesSoftDeletes.php),
  on the *model* rather than in the action that deletes — otherwise the cascade holds only for
  callers that remember to use that action. Only records that are genuinely deleted carry it:
  cities and product variants do; customers and products are deactivated, never deleted, so
  their children stay attached and come back with them.

### Two more traps

- **A mass delete fires no model events.** `$parent->children()->delete()` removes rows and
  records nothing — a hole in the history exactly where someone will look. Iterate:
  `->each(fn ($child) => $child->delete())`. These sets are small.
- **A pivot table has no model, so it has no history.** A role's permissions live in
  `role_has_permissions`, and it is the most consequential edit this API allows. It is recorded
  by hand in [RecordRolePermissionChange](app/Domain/Identity/Actions/RecordRolePermissionChange.php).
  Any future pivot that matters needs the same.

### Reading it

`GET /logs` is the whole feed; `GET /{resource}/{id}/logs` is one record's story, **including
the rows it owns** — a product's history covers its sizes, prices and photos, because "who put
25*35 up?" is the question it exists to answer and that number lives on another table. Filter
any of them with `event`, `causer_id`, `subject_type`, `from` and `to`. All behind `logs.view`,
which is deliberately *not* the permission that guards the record: someone allowed to edit
products is not automatically someone allowed to audit their colleagues.

### Restoring

The first restore endpoint is `POST orders/{order}/restore` — see
[RestoreOrder](app/Domain/Order/Actions/RestoreOrder.php) and
[the design](../Docs/orders/ORDER-DELETE-AND-ARCHIVE.md) §٥. **It does not cascade, and neither
will the next one:** a restore undoes the one delete it names, not everything that ever happened
to the record. `Order` never appears in `softDeleteCascades()` at all, for a reason that is
mechanical rather than philosophical — `Order::progress()` reads `transitions()` *without*
`withTrashed()`, so cascading them would draw an empty progress bar on every archived order and
break «تراجع عن الإلغاء» after a restore.

Recovering anything else deleted is still a console job.

---

## 11. Domain notes

**Customer codes** are `C1`, `C2`, `C3` … always `'C'` + the row id. The id is reserved from the
table's sequence *before* insert
([AllocateCustomerIdentifier](app/Domain/Customer/Actions/AllocateCustomerIdentifier.php)), so the
insert carries a final unique code, the column stays NOT NULL, and concurrent requests cannot
collide. Codes follow ids and may skip a number after a rolled-back transaction — they are
identifiers, not a count. This is the one place that depends on PostgreSQL.

**One phone, one customer.** A customer has exactly one `phone`, unique across the table. Users
likewise have exactly one. There is no multi-phone table and none should be added.

**Customers are deactivated, never deleted** (`is_active`), so history keeps pointing at a row that
still exists. There is deliberately no destroy route. An update that omits `is_active` leaves it
alone — omitting a field must never silently reactivate someone.

**Shops belong entirely to their customer** — no independent lifecycle, managed inline through the
customer's endpoints. Sending `shops` replaces the whole set (`id` = update, no `id` = create,
absent from the set = delete); omitting the key leaves them untouched. A shop dropped from the set
is soft deleted and the removal is recorded. Soft-deleting the *customer* leaves the shops
attached, so restoring one brings them back whole — see [§10](#10-soft-deletes-and-the-audit-trail).

**A warehouse holds stock items, not product sizes.** A `StockItem` is a material *at a size* —
«كيس شحن 25*35» — and it is what `warehouse_stocks`, `stock_movements`, `stock_batches`,
`purchase_order_items` and `stock_arrival_items` are all keyed on. Many product variants, across
different products, point at one through `product_variants.stock_item_id`: كيس شحن سادة 25*35 and
كيس شحن مطبوع 25*35 are two catalogue rows and one pile of bags. What separates those two products
is the printing, which is a `manufacturing_cost_rates` entry keyed per variant — not a different
material.

Sharing runs **across products at one size, never across sizes**. 25*35 and 35*40 are two stock
items, two balances, two FIFO stacks and two purchase order lines at two prices, which is what
keeps per-size costing intact.

Three consequences worth knowing before touching this:

- **An order's shortfall is totalled per stock item, not per line.** Two lines of 300 and 400
  drawing on one pile each fit inside a shelf of 500 alone; the order does not. See
  [DeductOrderStock](app/Domain/Order/Actions/DeductOrderStock.php).
- **`stock_items.unit` is what the shelf is counted in**, and it replaced `products.stock_unit`.
  A unit is a fact about the pile: two products sharing one must not be able to disagree about it.
  It still differs from `products.pricing_unit` on purpose — a thing bought in by weight and sold
  by the piece needs whole numbers on an order and fractional amounts off a shelf.
- **`product_variants.stock_item_id` is nullable**, because a quote-only size is never stocked.
  Every path that moves stock refuses such a size by name through
  [VariantHasNoStockItem](app/Domain/Inventory/Exceptions/VariantHasNoStockItem.php) rather than
  dereferencing null.

---

## 12. Git

- Feature branches; focused commits; the message explains **why**, not just what.
- The bar for merging: **Pint clean, `scramble:analyze` clean, whole suite green.**
- 🎯 Add CI (Pint + suite against PostgreSQL) so `main` cannot go red.

---

*Living document. When a rule here proves wrong, change it and record why — deliberate decisions
over cargo-culted ones.*
