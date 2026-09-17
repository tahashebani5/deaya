<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Actions\AllocateOrderIdentifier;

/**
 * What the person in the search box meant, worked out from the shape of what they typed.
 *
 * The orders screen has one box and three kinds of answer behind it — an order number, a
 * customer's code, a phone number — and asking staff to pick which one first is asking them to
 * do the classifying the screen can do itself. Every one of the three has a shape the other two
 * cannot wear:
 *
 * | typed        | meant                | why it cannot be one of the others          |
 * |--------------|----------------------|---------------------------------------------|
 * | `0912345678` | a phone number       | Libyan mobiles all start `09`               |
 * | `52`         | an order number      | order numbers are plain digits from 1 up    |
 * | `C7`         | a customer's code    | a letter then digits is the customer format |
 *
 * **`09` is what separates a phone from an order number**, and it works because order numbers
 * are allocated from 1 and never carry a leading zero — so no order will ever be typed as `09…`.
 * That is a property of {@see AllocateOrderIdentifier}, and if order
 * codes ever gain a format with leading zeros this rule has to be revisited. Said here so the
 * next person changing that format finds this.
 *
 * Anything matching none of the three is a **name**, and is searched as one. That is not in the
 * three the business named, but removing it would take away a search that works today for
 * somebody who only knows the customer by name.
 */
final readonly class OrderSearchTerm
{
    private function __construct(
        public OrderSearchKind $kind,
        public string $value,
    ) {}

    public static function from(string $raw): self
    {
        // Arabic-Indic digits first: `٠٩١٢` is what a Libyan keyboard produces, and classifying
        // it as a name because the digits are not ASCII would be a failure the user cannot
        // diagnose — they typed a phone number and the screen disagreed silently.
        $term = trim(self::toAsciiDigits($raw));

        // Spaces and dashes are how people write phone numbers down; they are punctuation, not
        // part of the number.
        $compact = preg_replace('/[\s\-]/', '', $term) ?? $term;

        if (preg_match('/^09\d*$/', $compact) === 1) {
            return new self(OrderSearchKind::Phone, $compact);
        }

        if (preg_match('/^\d+$/', $compact) === 1) {
            return new self(OrderSearchKind::OrderCode, $compact);
        }

        if (preg_match('/^[A-Za-z]+\d+$/', $compact) === 1) {
            // Codes are stored uppercase — `C7`, not `c7`. Nobody should have to hold shift to
            // find a customer.
            return new self(OrderSearchKind::CustomerCode, strtoupper($compact));
        }

        return new self(OrderSearchKind::Name, $term);
    }

    /**
     * Arabic-Indic (`٠١٢`) and Persian (`۰۱۲`) digits to ASCII.
     *
     * Both, because the two sets look alike and which one a keyboard sends is not something a
     * user chooses or can see.
     */
    private static function toAsciiDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
