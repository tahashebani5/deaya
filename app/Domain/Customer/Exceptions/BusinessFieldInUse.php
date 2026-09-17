<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Someone tried to delete a business field that shops are still recorded under.
 *
 * Refusing is the whole point of the rule. Deleting is soft, so the row would survive and the
 * shops would keep pointing at it — but the API would stop returning it, and every one of
 * those shops would render a field nobody can name or restore. Deactivating does what the
 * person actually wants: it disappears from the picker and leaves the record intact.
 */
final class BusinessFieldInUse extends DomainException
{
    public static function make(string $name, int $shopCount): self
    {
        return new self(
            "لا يمكن حذف «{$name}» لارتباطه بـ {$shopCount} من محلات العملاء. أوقفه بدل حذفه ليختفي من قوائم الاختيار.",
        );
    }
}
