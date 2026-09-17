<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\AuditReferenceNames;

/**
 * What to call this record when another record's history points at it.
 *
 * A history line saying `العميل: 12` names nothing. {@see AuditReferenceNames}
 * reads a record's `name`, `label`, `title` or `code` — in that order — which is the right
 * answer for almost every table we have, and needs no code at all to be right.
 *
 * This contract is for the rest: a record whose readable name is none of those columns, or is
 * built from more than one of them. Implement it and that answer wins.
 *
 * Returning an empty string is the same as having no name — the history falls back to the raw
 * id, which is honest, rather than printing a blank where a name should be.
 */
interface HasAuditDisplayName
{
    public function auditDisplayName(): string;
}
