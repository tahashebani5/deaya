<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\Audience\NotificationAudience;
use App\Domain\Notification\DTOs\RenderedNotification;
use App\Domain\Notification\Enums\NotificationType;

/**
 * One kind of notification: who hears it, and how it reads.
 *
 * **This interface is the extension point.** Adding a notification to the system is a case in
 * {@see NotificationType} and a class implementing this — no
 * migration, no endpoint, no client release. Everything else in the context is written once and
 * never touched again.
 *
 * Resolved from the container, so a definition may constructor-inject whatever Service it needs
 * to build its sentence.
 */
interface NotificationDefinition
{
    /**
     * Who should hear about it.
     *
     * Takes the payload because the audience is sometimes a fact about the event — the investor
     * behind *this* deal, the author of *that* note — rather than a constant.
     *
     * @param  array<string, mixed>  $payload
     */
    public function audience(array $payload): NotificationAudience;

    /**
     * The sentence, built from the frozen payload.
     *
     * **Called at read time, not at publish time.** Nothing rendered here is stored, which is
     * what lets an Arabic typo be fixed by a deploy instead of living forever in every mailbox
     * that already received it. The payload it reads is a snapshot taken when the event
     * happened, so the words stay true even after the order behind them is gone.
     *
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload): RenderedNotification;

    /**
     * Whether the person whose action caused this should hear about it too.
     *
     * **Almost always `false`**, and it is asked rather than assumed so each definition makes
     * the decision deliberately. Somebody who has just moved an order to «نواقص» does not need
     * to be told that they moved an order to «نواقص»; left on, every action notifies its own
     * author and the bell becomes an echo of the user's own morning.
     */
    public function notifiesCauser(): bool;
}
