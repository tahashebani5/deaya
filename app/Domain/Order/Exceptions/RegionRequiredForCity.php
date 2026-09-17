<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

final class RegionRequiredForCity extends DomainException
{
    public static function make(string $cityName): self
    {
        return new self("يجب اختيار المنطقة داخل «{$cityName}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['region_id' => [$this->getMessage()]];
    }
}
