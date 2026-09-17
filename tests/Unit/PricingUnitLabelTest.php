<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Catalog\Enums\PricingUnit;
use PHPUnit\Framework\TestCase;

/**
 * The word the app prints beside a quantity — «كجم», not «كيلوغرام».
 *
 * The owner's instruction, on seeing «174.9 كيلوغرام» on a deal: «افضل من الاسم الكامل — بكل
 * مكان». The full word is four syllables of column width on a phone, and every screen that names
 * a unit sits beside a number where the abbreviation reads faster and wraps less.
 *
 * This is the single source those words come from, so it is pinned here rather than in the nine
 * resources that print it: change one line and every screen, every error and every field label
 * moves together — which is exactly what «بكل مكان» asks for.
 *
 * Arrange - Act - Assert.
 */
class PricingUnitLabelTest extends TestCase
{
    public function test_a_weighed_unit_is_abbreviated(): void
    {
        // Arrange
        $unit = PricingUnit::Kilogram;

        // Act
        $label = $unit->label();

        // Assert
        $this->assertSame('كجم', $label);
    }

    public function test_a_counted_unit_keeps_its_whole_word(): void
    {
        // Arrange — «قطعة» is already as short as the word gets; there is nothing to abbreviate.
        $unit = PricingUnit::Piece;

        // Act
        $label = $unit->label();

        // Assert
        $this->assertSame('قطعة', $label);
    }
}
