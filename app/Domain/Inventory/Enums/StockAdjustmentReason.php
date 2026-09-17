<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

use App\Domain\Inventory\Actions\RecordStockMovement;
use App\Domain\Inventory\Actions\SetStockItemUnit;

/**
 * Why a shelf holds less than the book said.
 *
 * **A reason rather than a `MovementType`.** A new movement type needs an arm in `label()`,
 * `requiresSource()`, `requiresDestination()`, `isDirectional()` and
 * {@see RecordStockMovement::batchSource()}, and `StockLedgerTest`
 * computes a balance by reading *which end of the row is filled* — so a type shaped even
 * slightly wrong inverts the ledger invariant without failing anything. This rides along on a
 * movement whose shape is already correct, and answers the one question `notes` could never be
 * queried for: «كم هالك هذا الشهر؟».
 *
 * Only ever set on a **decreasing** adjustment. An increase found more than the book said,
 * which is not a loss and has no member here.
 */
enum StockAdjustmentReason: string
{
    /** Goods destroyed or spoiled — broken, wet, no longer sellable. */
    case Damage = 'damage';

    /** Stock the book has and the shelf does not, with no known event behind it. */
    case Shortage = 'shortage';

    /** A counting error being put right, rather than goods that went anywhere. */
    case CountCorrection = 'count_correction';

    /**
     * The discards {@see SetStockItemUnit} posts before relabelling a shelf's unit.
     *
     * **Never selectable by a person** — `RecordAdjustmentRequest` refuses it. It exists so that
     * a system-generated write-down is not counted as a loss somebody caused, which is exactly
     * what it would have looked like inside the free-text notes it replaces.
     */
    case UnitChange = 'unit_change';

    public function label(): string
    {
        return match ($this) {
            self::Damage => 'هالك',
            self::Shortage => 'عجز',
            self::CountCorrection => 'فرق جرد',
            self::UnitChange => 'تغيير وحدة القياس',
        };
    }

    /** Whether a storekeeper may choose this when recording a decrease. */
    public function isRecordableByHand(): bool
    {
        return $this !== self::UnitChange;
    }

    /**
     * The ones a person may choose, for a validation rule and for a picker.
     *
     * @return array<int, string>
     */
    public static function recordableValues(): array
    {
        return array_values(array_map(
            fn (self $reason) => $reason->value,
            array_filter(self::cases(), fn (self $reason) => $reason->isRecordableByHand()),
        ));
    }
}
