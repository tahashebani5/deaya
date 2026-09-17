<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Models\StockBatchConsumption;

/**
 * One {@see StockBatchConsumption} row, in memory, before or after
 * it is written.
 *
 * Carries enough of the batch it drew from — its cost, when the stock was received, and where
 * that cost came from — for two callers that need more than a persisted row's id:
 * `RecordStockMovement` sums these to cost a movement, and an internal transfer's destination
 * uses them to recreate the same layers at the new warehouse without inventing a `received_at` or
 * losing the original {@see StockBatchSourceType}.
 */
final readonly class BatchDraw
{
    public function __construct(
        public int $stockBatchId,
        public string $quantity,
        public string $unitCost,
        public string $totalCost,
        public string $receivedAt,
        public StockBatchSourceType $sourceType,
        public ?int $stockArrivalItemId,
        /**
         * The movement that opened the layer this was drawn from — carried so a transfer's
         * destination recreates it pointing at the same event, exactly as it already keeps the
         * original `received_at`. Null for every layer opened before that column existed.
         */
        public ?int $stockMovementId = null,
        /**
         * Who financed the layer this was drawn from — carried for the same reason
         * `receivedAt` is: a transfer's destination layer must keep it, or the stock silently
         * changes owner by moving shelves. Null means the company's own stock, which is most of
         * every shelf.
         */
        public ?int $investorDealId = null,
        /**
         * سعر السادة the deal that financed this layer sells it to the press at — carried for
         * the reason `investorDealId` above is, and it is the same mistake if it is dropped: a
         * transfer's destination layer would lose the price and the next printed order would
         * take an investor's goods at cost.
         */
        public ?string $printingSalePrice = null,
    ) {}
}
