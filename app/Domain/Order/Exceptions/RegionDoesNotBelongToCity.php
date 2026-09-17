<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

final class RegionDoesNotBelongToCity extends DomainException
{
    public static function make(int $regionId, string $cityName): self
    {
        return new self("المنطقة رقم {$regionId} لا تنتمي إلى «{$cityName}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['region_id' => [$this->getMessage()]];
    }
}
