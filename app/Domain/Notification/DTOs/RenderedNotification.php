<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

use App\Domain\Notification\Contracts\NotificationDefinition;

/**
 * A notification as a person reads it.
 *
 * Produced by a {@see NotificationDefinition} at read time and
 * never stored — see that interface for why the words are code rather than data.
 */
final readonly class RenderedNotification
{
    /**
     * @param  string  $title  one line, the thing itself
     * @param  string  $body  one more line of detail; never a paragraph
     * @param  string|null  $route  where tapping it goes, as a client-side path — `/orders/145`.
     *                              **Null is ordinary**: an announcement has nothing to open,
     *                              and the app must render a tile that simply does not navigate.
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $route = null,
    ) {}
}
