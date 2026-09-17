<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DecimalText;
use PHPUnit\Framework\TestCase;

/**
 * The padding a decimal column adds, taken off before a person reads it.
 *
 * **Why a column's scale reaches the screen at all.** `decimal:3` is what a shelf weighed in
 * kilograms needs, and every quantity is stored at that scale whether it is a weight or a count
 * of bags — so a thousand bags comes out of the database as «1000.000» and goes into a hint, a
 * label and the box the delivery screen opens holding. Three zeros nobody typed, on a figure a
 * storekeeper has to read at arm's length.
 *
 * **The fraction is kept where there is one.** Half a kilo is «12.5», not «12» — this drops the
 * padding, never a digit that was measured.
 *
 * Arrange - Act - Assert throughout.
 */
class DecimalTextTest extends TestCase
{
    public function test_a_count_loses_the_scale_the_column_padded_it_with(): void
    {
        // Arrange — a thousand bags, as `decimal:3` hands them over.
        $quantity = '1000.000';

        // Act
        $text = DecimalText::trim($quantity);

        // Assert
        $this->assertSame('1000', $text);
    }

    public function test_money_loses_its_two_places_the_same_way(): void
    {
        // Arrange — what is left on an order, at `Money::SCALE`.
        $remaining = '3880.00';

        // Act
        $text = DecimalText::trim($remaining);

        // Assert
        $this->assertSame('3880', $text);
    }

    public function test_a_measured_fraction_survives(): void
    {
        // Arrange — twelve and a half kilograms off a scale.
        $weight = '12.500';

        // Act
        $text = DecimalText::trim($weight);

        // Assert
        $this->assertSame('12.5', $text);
    }

    public function test_a_whole_number_with_no_point_keeps_its_zeros(): void
    {
        // Arrange — the case the two hand-rolled trims this replaces got wrong:
        // `rtrim('300', '0')` is «3».
        $quantity = '300';

        // Act
        $text = DecimalText::trim($quantity);

        // Assert
        $this->assertSame('300', $text);
    }

    public function test_nothing_is_still_nothing(): void
    {
        // Arrange
        $zero = '0.000';

        // Act
        $text = DecimalText::trim($zero);

        // Assert
        $this->assertSame('0', $text);
    }

    public function test_a_negative_keeps_its_sign(): void
    {
        // Arrange — a reversal entry, as the ledger writes it.
        $amount = '-1500.00';

        // Act
        $text = DecimalText::trim($amount);

        // Assert
        $this->assertSame('-1500', $text);
    }
}
