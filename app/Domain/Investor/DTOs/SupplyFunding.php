<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\InvestorService;

/**
 * Who financed one line of an arriving lorry, and what they sell it to the press for.
 *
 * The answer {@see InvestorService::dealForSupply()} gives, and the two facts a cost layer must
 * freeze together: a deal id with no price would open a layer the press takes at cost, and a
 * price with no deal has nobody to pay it.
 *
 * Two fields rather than a bare id, because the caller — `ReceivePurchaseOrder` — asks this
 * question once per line and must not ask a second time for the half that travels with it.
 */
final readonly class SupplyFunding
{
    public function __construct(
        public int $dealId,
        /** Null on a deal opened without the term: its stock is costed to an order at cost. */
        public ?string $printingSalePrice = null,
    ) {}
}
