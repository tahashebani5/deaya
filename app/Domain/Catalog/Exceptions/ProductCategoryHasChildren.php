<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Someone tried to delete a heading that still holds subheadings.
 *
 * Refused before the product check, and for a stronger reason: deleting is soft, so a parent
 * removed from under its children would leave them pointing at a row the API no longer returns
 * — headings nobody can name, restore or re-file. Emptying it first is a decision about where
 * those subheadings belong, and only a person can make it.
 */
final class ProductCategoryHasChildren extends DomainException
{
    public static function make(string $name, int $childCount): self
    {
        return new self(
            "لا يمكن حذف «{$name}» لأنه يحتوي {$childCount} من التصنيفات الفرعية. انقلها أو احذفها أولاً.",
        );
    }
}
