<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A deal may only be opened against a shelf whose products are **all** investable.
 *
 * Every active product standing on that shelf, not merely one of them. A shelf shared with a
 * product outside the investable headings would have the investor's money financing goods sold
 * at another product's margin — and FIFO cannot tell the two apart, because neither the shelf
 * nor the movement knows what was on the invoice.
 */
final class StockItemIsNotInvestable extends DomainException
{
    public static function make(string $name): self
    {
        return new self("المادة «{$name}» ليست ضمن التصنيفات القابلة للاستثمار");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
