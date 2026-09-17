<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Str;

/**
 * Builds the URL-safe identifier for a product nobody typed one for.
 *
 * The rule, in order:
 *
 *   1. `Str::slug($name)` — a Latin name gives a readable slug, `Shipping Bag` → `shipping-bag`.
 *   2. Nothing left? Use the code, lowercased: `P11` → `p11`. `Str::slug` strips non-ASCII, so an
 *      Arabic-only name — which is most of this catalogue — reduces to an empty string, and an
 *      empty slug would violate a NOT NULL column with a unique index.
 *   3. Already taken? Suffix the code: `shipping-bag-p11`. The code is unique by construction, so
 *      one pass is always enough — there is no loop here that can spin, and no second query that
 *      can race, because the suffix does not depend on what else exists.
 *
 * **Why the code and not a counter.** `slug-2`, `slug-3` needs a query to find the highest
 * existing suffix, and two concurrent inserts both read the same answer. The code is already
 * reserved for this row before the insert, by {@see AllocateProductIdentifier}, so it is unique
 * without asking the database anything.
 *
 * Truncated to the column's 80 characters before the suffix is added, never after: a slug cut in
 * half at the end would lose exactly the part that makes it unique.
 */
final class GenerateProductSlug
{
    /** Matches the `slug` column's length. */
    public const MAX_LENGTH = 80;

    public function __invoke(string $name, string $code): string
    {
        $fallback = Str::lower($code);
        $suffix = '-'.$fallback;

        $base = Str::slug($name);
        if ($base === '') {
            return $fallback;
        }

        $base = Str::limit($base, self::MAX_LENGTH - Str::length($suffix), '');
        // Limiting can leave the hyphen a word ended on, which would read as `bag--p11`.
        $base = Str::of($base)->trim('-')->toString();

        if ($base === '') {
            return $fallback;
        }

        return $this->isTaken($base) ? $base.$suffix : $base;
    }

    /**
     * Soft-deleted products are included on purpose: their slug still occupies the unique index
     * only while they are live, but reusing the slug of a product somebody deleted last week is
     * how an old link starts pointing at a different bag.
     */
    private function isTaken(string $slug): bool
    {
        return Product::withTrashed()->where('slug', $slug)->exists();
    }
}
