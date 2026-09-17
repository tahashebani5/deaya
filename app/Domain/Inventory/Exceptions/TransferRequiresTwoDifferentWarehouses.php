<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A transfer named the same warehouse at both ends.
 *
 * It would decrement and increment the same balance row, leaving the shelf exactly as it was
 * while adding a ledger entry claiming stock moved. Worse than a no-op: the reconciliation is
 * still correct, so nothing ever flags it, and the history now contains a movement that never
 * happened.
 *
 * A database CHECK holds the same rule — this is what makes the refusal readable.
 */
final class TransferRequiresTwoDifferentWarehouses extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن التحويل من المخزن إلى نفسه — اختر مخزناً مختلفاً للوجهة');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['to_warehouse_id' => [$this->getMessage()]];
    }
}
