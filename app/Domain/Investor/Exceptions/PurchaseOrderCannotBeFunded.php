<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Who paid for a shipment is declared before it arrives, and declared once **per line**.
 *
 * After the first line is received there are cost layers on the shelf that no deal can be
 * stamped onto afterwards, and FIFO will sell them as the company's. One order may carry several
 * deals — the claim is per line — but a second deal over the *same* line would give the receipt
 * two answers to a question that takes one.
 */
final class PurchaseOrderCannotBeFunded extends DomainException
{
    public static function alreadyArriving(): self
    {
        return new self('بدأ استلام هذا الأمر — لا يُقرَّر تمويله بعد وصول بضاعته');
    }

    public static function hasNoLines(): self
    {
        return new self('أمر الشراء بلا بنود — أضف ما تشتريه أولاً');
    }

    public static function lineAlreadyFunded(string $code): self
    {
        return new self("هذا البند ممول بالفعل ضمن الصفقة {$code} — اختر بنداً آخر");
    }

    public static function everyLineIsFunded(): self
    {
        return new self('كل بنود هذا الأمر ممولة بالفعل');
    }

    public static function noLinesChosen(): self
    {
        return new self('اختر بنداً واحداً على الأقل تموّله الصفقة');
    }

    public static function moreThanTheGoodsCost(string $funded, string $cost): self
    {
        return new self(
            "التمويل ({$funded}) أكبر من تكلفة البنود المختارة ({$cost}) — لا يُموَّل أكثر من ثمن البضاعة"
        );
    }

    public static function linesHaveNoCost(): self
    {
        return new self('البنود المختارة بلا تكلفة — أدخل سعرها في أمر الشراء أولاً');
    }

    public static function lineIsNotOnTheOrder(): self
    {
        return new self('هذا البند ليس من بنود أمر الشراء');
    }

    public static function notFound(): self
    {
        return new self('أمر الشراء غير موجود');
    }

    public static function nothingWasPutIn(): self
    {
        return new self('اكتب ما وضعه كل مستثمر — لا تُفتح صفقة بلا تمويل');
    }

    public static function stakeIsTooSmall(string $minimum): self
    {
        return new self("أقل مبلغ يدخل به مستثمر صفقةً هو {$minimum} د.ل");
    }

    public static function listedTwice(): self
    {
        return new self('المستثمر مكرَّر — سطر واحد لكل مستثمر بمجموع ما وضعه');
    }
}
