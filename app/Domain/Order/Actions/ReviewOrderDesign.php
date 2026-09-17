<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Exceptions\DesignAlreadyReviewed;
use App\Domain\Order\Exceptions\DesignRejectionRequiresReason;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use Illuminate\Support\Facades\DB;

/**
 * Records the customer's verdict on one version.
 *
 * **A version is judged once.** Changing a verdict afterwards would erase the reason the next
 * version exists, and that trail is the entire value of keeping versions at all.
 *
 * Approving supersedes whatever was approved before. The database allows exactly one approved
 * version per order — that partial index is what makes "which one do we print?" answerable on
 * the shop floor — so the previous winner is stepped back to rejected, with a reason saying what
 * replaced it rather than a blank.
 */
final class ReviewOrderDesign
{
    /**
     * @throws DesignAlreadyReviewed
     * @throws DesignRejectionRequiresReason
     */
    public function __invoke(
        Order $order,
        OrderDesign $design,
        OrderDesignStatus $verdict,
        ?string $reason = null,
        ?User $actor = null,
    ): OrderDesign {
        if ($design->status->isReviewed()) {
            throw DesignAlreadyReviewed::make((int) $design->version, $design->status);
        }

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($verdict->requiresReason() && $reason === null) {
            throw DesignRejectionRequiresReason::make();
        }

        return DB::transaction(function () use ($order, $design, $verdict, $reason, $actor): OrderDesign {
            if ($verdict === OrderDesignStatus::Approved) {
                $this->supersedePreviouslyApproved($order, (int) $design->version);
            }

            $design->forceFill([
                'status' => $verdict,
                'rejection_reason' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $actor?->getKey(),
            ])->save();

            return $design->refresh();
        });
    }

    private function supersedePreviouslyApproved(Order $order, int $newVersion): void
    {
        $order->designs()
            ->where('status', OrderDesignStatus::Approved)
            ->get()
            // One at a time: a mass update fires no model events, and the history would lose
            // the moment a design stopped being the approved one.
            ->each(fn (OrderDesign $previous) => $previous->forceFill([
                'status' => OrderDesignStatus::Rejected,
                'rejection_reason' => "تم اعتماد التصميم رقم {$newVersion} بدلاً منه",
            ])->save());
    }
}
