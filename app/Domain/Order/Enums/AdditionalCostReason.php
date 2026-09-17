<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Order\Models\Order;

/**
 * Why an order is being charged more than its products come to.
 *
 * **A closed set rather than a free-text box, and that is the load-bearing decision.** The
 * obvious version of this feature was an amount and a sentence — «تغليف خاص»، «نقل للفرع» —
 * typed by whoever took the order. It was rejected for one reason: this figure is money the
 * business collects, and the question asked of collected money is eventually «كم حصّلنا مقابل
 * التغليف هذا الربع؟». A column filled by hand answers that question with «تغليف»، «تغليف
 * خاص»، «كرتون» and «تغليف!!» — four spellings of one category and no way to group them.
 *
 * So the category is a code and the words beside it are the detail, not the classification. See
 * `Order::$additional_cost_note`.
 *
 * **It is also what keeps the door open.** Should the business outgrow one charge per order and
 * need a list of them, every existing order can be carried into that table with its category
 * intact — a backfill from prose could only have guessed. That path is written down in
 * ORDER-ADDITIONAL-COST.md rather than left to be rediscovered.
 *
 * Cast on {@see Order}, which is the whole of what makes the change history read
 * «تغليف خاص» instead of `special_packaging` — see `AuditValueLabels`.
 */
enum AdditionalCostReason: string
{
    /** A box, a wrap, a bag inside the bag — anything the goods travel in that was asked for. */
    case SpecialPackaging = 'special_packaging';

    /** Work done for this customer that no line on the order describes. */
    case ExtraService = 'extra_service';

    /** A change to what was already agreed, charged rather than absorbed. */
    case Modification = 'modification';

    /** Moving the goods somewhere the delivery price does not cover. */
    case Transport = 'transport';

    /**
     * Everything the four above do not name — and the one case that *requires* the note beside
     * it, because «أخرى» on its own carries no information at all.
     */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SpecialPackaging => 'تغليف خاص',
            self::ExtraService => 'خدمة إضافية',
            self::Modification => 'تعديل',
            self::Transport => 'نقل',
            self::Other => 'أخرى',
        };
    }

    /** Whether this reason is meaningless without words of its own. */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $reason) => $reason->value, self::cases());
    }
}
