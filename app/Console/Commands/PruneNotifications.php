<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keeps the notification tables bounded.
 *
 * **The only thing standing between this feature and a table that grows forever.** Notifications
 * are written on every shortage, every announcement and every future event in the catalogue,
 * multiplied by the number of employees — and unlike the audit trail, nothing here is worth
 * keeping once it has been read and aged out. That is precisely why these models are exempt from
 * soft deletes: a "deleted" notification still occupying the row it was pruned to reclaim would
 * defeat the exercise.
 *
 * **Read and unread age differently on purpose.** Something already seen is finished with, and
 * ninety days is generous for it. Something never opened is either still wanted or evidence that
 * somebody was away for a long time, and throwing it out at the same age would quietly delete
 * the notifications of the person most likely to need them.
 *
 * Deleting the parent takes its recipient rows with it — `cascadeOnDelete` genuinely fires here,
 * unlike everywhere else in this schema, because these rows are actually deleted.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune
                            {--read-days=90 : Delete read notifications older than this}
                            {--unread-days=365 : Delete unread notifications older than this}
                            {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete notifications that have aged out';

    public function handle(): int
    {
        $readDays = (int) $this->option('read-days');
        $unreadDays = (int) $this->option('unread-days');
        $dryRun = (bool) $this->option('dry-run');

        // Anything past the outer horizon goes whatever its state — nobody is coming back for a
        // year-old unread notification, and the row is the cost of pretending otherwise.
        $expired = Notification::query()
            ->where('created_at', '<', Carbon::now()->subDays($unreadDays));

        // Between the two horizons, only what everybody who received it has already read. A
        // notification one person still has unopened stays for all of them: they share a row,
        // and splitting that would mean the fan-out could never be cleaned up as a unit.
        $readAndOld = Notification::query()
            ->where('created_at', '<', Carbon::now()->subDays($readDays))
            ->whereDoesntHave('recipients', fn ($query) => $query->whereNull('read_at'));

        $expiredCount = $expired->count();
        $readCount = $readAndOld->count();

        if ($dryRun) {
            $this->info("Would delete {$expiredCount} expired and {$readCount} read notifications.");

            return self::SUCCESS;
        }

        $expired->delete();
        $readAndOld->delete();

        // Recipient rows whose notification is already gone. Belt and braces: the cascade should
        // have taken them, and a row here would be invisible to every screen while still
        // counting toward somebody's unread badge.
        $orphans = NotificationRecipient::query()
            ->whereDoesntHave('notification')
            ->delete();

        $this->info("Deleted {$expiredCount} expired, {$readCount} read, {$orphans} orphaned rows.");

        return self::SUCCESS;
    }
}
