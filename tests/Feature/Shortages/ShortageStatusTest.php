<?php

declare(strict_types=1);

namespace Tests\Feature\Shortages;

use App\Domain\Shortage\Enums\ShortageStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The chase's state machine, asserted as a whole rather than one path at a time.
 *
 * **This file is the specification.** The map lives in one `match` on the enum and everything
 * else reads it from there — the API's refusal, the buttons the app draws, the chips on the
 * board. So it is worth pinning exhaustively: adding a status or loosening a rule shows up here
 * as a failing assertion naming exactly what changed, rather than as a surprise on a screen.
 *
 * The structural tests at the bottom are the ones that earn their keep when somebody adds a
 * fifth status: they fail if it has no label, no way in, or a way out naming something that does
 * not exist.
 *
 * Arrange - Act - Assert throughout.
 */
class ShortageStatusTest extends TestCase
{
    /**
     * Every legal move a person may make, written out.
     *
     * @return array<string, array{0: ShortageStatus, 1: list<ShortageStatus>}>
     */
    public static function transitions(): array
    {
        return [
            'a new shortage is picked up, or found to be unobtainable straight away' => [
                ShortageStatus::New,
                [ShortageStatus::Searching, ShortageStatus::Unavailable],
            ],
            'a chase ends in giving up — completion is the arithmetic\'s, never a choice' => [
                ShortageStatus::Searching,
                [ShortageStatus::Unavailable],
            ],
            'giving up is not final: the sack turns up next month and the chase resumes' => [
                ShortageStatus::Unavailable,
                [ShortageStatus::Searching],
            ],
            'a completed shortage is finished — the record stands and nothing follows' => [
                ShortageStatus::Completed,
                [],
            ],
        ];
    }

    /**
     * @param  list<ShortageStatus>  $expected
     */
    #[DataProvider('transitions')]
    public function test_the_map_is_exactly_what_the_business_described(
        ShortageStatus $from,
        array $expected,
    ): void {
        // Arrange — the map is the enum itself.

        // Act
        $allowed = $from->allowedNext();

        // Assert
        $this->assertEqualsCanonicalizing($expected, $allowed);

        foreach (ShortageStatus::cases() as $target) {
            $this->assertSame(
                in_array($target, $expected, true),
                $from->canMoveTo($target),
                "{$from->value} → {$target->value}",
            );
        }
    }

    /**
     * The rule the whole feature turns on: «لا يتحوّل النقص إلى مكتمل إلا بعد توفير كامل الكمية».
     *
     * Enforced by absence rather than by a guard — «مكتمل» is in no map at all — so this asserts
     * the absence directly. A future edit that adds it to one map for convenience fails here
     * instead of quietly letting a clerk close a shortage with twenty kilos still missing.
     */
    public function test_completion_is_not_reachable_from_anywhere_by_hand(): void
    {
        // Arrange / Act / Assert
        foreach (ShortageStatus::cases() as $from) {
            $this->assertNotContains(
                ShortageStatus::Completed,
                $from->allowedNext(),
                "«مكتمل» must not be reachable from {$from->value}",
            );
        }
    }

    /**
     * «جديد» means nobody has started. Nothing may make that true again.
     */
    public function test_nothing_leads_back_to_new(): void
    {
        // Arrange / Act / Assert
        foreach (ShortageStatus::cases() as $from) {
            $this->assertNotContains(ShortageStatus::New, $from->allowedNext());
        }
    }

    /**
     * «غير متوفر» reads like an ending and is not one — the single most likely thing for a reader
     * to get wrong about this enum, so it is asserted on its own.
     */
    public function test_only_completion_is_final(): void
    {
        // Arrange / Act / Assert
        $this->assertTrue(ShortageStatus::Completed->isFinal());
        $this->assertFalse(ShortageStatus::Unavailable->isFinal());
        $this->assertFalse(ShortageStatus::New->isFinal());
        $this->assertFalse(ShortageStatus::Searching->isFinal());

        foreach (ShortageStatus::cases() as $status) {
            $this->assertSame(! $status->isFinal(), $status->isOpen());
        }
    }

    /**
     * A supply against an abandoned shortage puts it back in the chase, and leaves every other
     * status alone.
     */
    public function test_only_an_abandoned_shortage_is_reopened_by_a_supply(): void
    {
        // Arrange / Act / Assert
        $this->assertSame(ShortageStatus::Searching, ShortageStatus::Unavailable->reopensTo());

        $this->assertNull(ShortageStatus::New->reopensTo());
        $this->assertNull(ShortageStatus::Searching->reopensTo());
        $this->assertNull(ShortageStatus::Completed->reopensTo());
    }

    /**
     * The one that fails when somebody adds a fifth case and stops halfway.
     */
    public function test_every_status_has_a_label_and_a_reachable_map(): void
    {
        // Arrange / Act / Assert
        foreach (ShortageStatus::cases() as $status) {
            $this->assertNotSame('', trim($status->label()), "{$status->value} has no label");

            foreach ($status->allowedNext() as $target) {
                $this->assertInstanceOf(ShortageStatus::class, $target);
            }
        }

        $this->assertSame(
            ['new', 'searching', 'unavailable', 'completed'],
            ShortageStatus::values(),
        );
    }

    /**
     * Every status a person may choose is reachable from somewhere, and «مكتمل» is the one that
     * is deliberately not — reached by arithmetic instead.
     */
    public function test_no_status_but_completion_is_a_dead_end_nobody_can_enter(): void
    {
        // Arrange
        $reachable = [];

        foreach (ShortageStatus::cases() as $from) {
            foreach ($from->allowedNext() as $target) {
                $reachable[$target->value] = true;
            }
        }

        // Act / Assert — «جديد» is where a shortage starts, so it needs no way in.
        $this->assertArrayHasKey(ShortageStatus::Searching->value, $reachable);
        $this->assertArrayHasKey(ShortageStatus::Unavailable->value, $reachable);
        $this->assertArrayNotHasKey(ShortageStatus::Completed->value, $reachable);
        $this->assertArrayNotHasKey(ShortageStatus::New->value, $reachable);
    }
}
