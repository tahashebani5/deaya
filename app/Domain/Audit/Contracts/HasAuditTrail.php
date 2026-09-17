<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Concerns\Auditable;

/**
 * A record with a history endpoint of its own — `GET /products/{product}/logs`.
 *
 * Not every audited model has one. A price tier is logged, but nobody opens a screen for a
 * price tier; its history surfaces through the product that owns it. This interface marks the
 * records that *are* the thing a user is looking at.
 *
 * Implemented by {@see Auditable} for free, so a model becomes one by declaring the interface.
 * The declaration is the point: it is what lets the audit module take any such model without
 * knowing which context it came from, keeping the dependency pointing one way.
 */
interface HasAuditTrail
{
    /**
     * Every subject whose history belongs to this record, as morph alias => ids.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array;

    /**
     * The record's own primary key.
     *
     * Declared without a return type on purpose. Eloquent's own `getKey()` and
     * `getMorphClass()` carry no return type, and a return type is covariant — an
     * implementation may narrow it but never widen it. Writing `: mixed` here therefore made
     * every model that implements this interface unloadable:
     *
     *   Declaration of Model::getKey() must be compatible with HasAuditTrail::getKey(): mixed
     *
     * The types live in the docblocks instead, where static analysis still reads them and PHP
     * does not have to enforce a signature the framework cannot satisfy.
     *
     * @return int|string|null
     */
    public function getKey();

    /**
     * The morph alias this record is stored under — 'product', 'city' …
     *
     * @return string
     */
    public function getMorphClass();
}
