<?php

declare(strict_types=1);

namespace App\Domain\Order\Support;

use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Actions\DeleteOrder;
use App\Domain\Order\Actions\RestoreOrder;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\DecimalText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What the button about to be pressed will do — to the ledger first, and to the warehouse after.
 *
 * **The precedent is {@see TransitionFields::deductionPreview()}, and both of its rules are
 * inherited whole.** It builds an Arabic warning on the server and hands it down as part of the
 * response, so every client shows it without shipping a release; and it reads
 * {@see OrderItem::producedQuantity()} — *the same accessor the action itself deducts against* —
 * so the sentence cannot drift from the movement. A preview computed in Dart from `quantity`
 * would have been silently wrong on every line sold by the piece and stocked by the kilo.
 *
 * **Two sections in one envelope, and the money comes first.** §٧٫١ decided both halves of that.
 * Since the delete began reversing payments rather than refusing them (§٢٫١), one button can
 * undo a cash collection — a *heavier* consequence than anything it does to a shelf — so the
 * money may not be read after the stock or tucked onto the end of a line. And the two travel
 * under one key rather than two, because the screen draws them in one dialog: split across
 * `stock_effect` and `money_effect`, their order would become a decision in Dart instead of a
 * decision here.
 *
 * **A section with nothing to say is absent, not empty.** An order carrying no live payments
 * gets no `money` at all — not a section holding «لا يوجد», which is a line a person has to read
 * before discovering it says nothing. The stock half keeps its own «لا شيء يعود» sentence, and
 * that asymmetry is deliberate: the stock section is always drawn, so a blank one would read as
 * a bug, while the money section is drawn only when it exists.
 *
 * **One class for two opposite questions**, because the order itself decides which is being
 * asked: a live order is looking at a delete, an archived one at a restore. Splitting them would
 * have both callers deciding which to call from `deleted_at`, which is the branch below.
 *
 * **Derived from the ledger, never from `orders.stock_deducted_at` and never from
 * `paid_amount`** — see {@see Order::linesWithStockStillDrawn()} and
 * {@see Order::liveCreditEntries()}, the two queries the delete itself runs. That is what §٧٫١
 * means by «ومن نفس الدفتر الذي سيعكسه الحذف»: the figure shown cannot differ from the figure
 * written, because there is one query behind both.
 *
 * **The show response only.** The lines are per-order queries and the notes are paragraphs; on a
 * list of twenty archived orders it would be forty queries to draw text nobody can read at that
 * size. See §٧ of Docs/orders/ORDER-DELETE-AND-ARCHIVE.md.
 *
 * @phpstan-type PreviewLine array{label: string, quantity: string, unit: string}
 * @phpstan-type MoneyLine array{label: string, amount: string, currency: string}
 * @phpstan-type MoneySection array{kind: string, warning: string, lines: list<MoneyLine>, note: string|null}
 * @phpstan-type StockSection array{kind: string, warning: string, lines: list<PreviewLine>, note: string|null}
 */
final class StockEffectPreview
{
    private const RETURN_HEADLINE = 'سيُعاد إلى المخزن ما خصمته هذه الطلبية:';

    private const REDEDUCT_HEADLINE = 'سيُخصم من المخزن من جديد:';

    private const REVERSE_HEADLINE = 'سيُعكس ما قُبض على هذه الطلبية:';

    /**
     * **The sentence the whole money section exists for.** Somebody who reads «سيُعكس» alone
     * concludes that a delete is financially undoable, and it is not: §٢٫١ ruled that the restore
     * does not un-reverse, because reversing a reversal is a third financial event nobody asked
     * for. So the consequence is spelt out with the remedy beside it — the payment goes back in
     * by hand — rather than left to be discovered by an accountant next month.
     */
    private const REVERSE_NOTE = 'والاستعادة لا تُعيدها: إن كان المال قد قُبض فعلاً فتُسجَّل الدفعة من جديد بيدٍ';

    /** The matching line on the other side, so nobody waits for the money to reappear. */
    private const NOT_RESTORED = 'الدفعات المعكوسة لا تعود';

    /**
     * **The price of a restore, stated rather than discovered.** «إلغاء تام» is undone without a
     * new deduction because the cancellation was a real event; a delete says the order should
     * never have been written down, so restoring it must put the goods back out — and a draw made
     * today eats today's cost layers, not the ones it ate the first time. The order therefore
     * comes back at a different cost, which is a fact the person pressing the button is entitled
     * to before they press it. See §١.
     */
    private const REDEDUCT_NOTE = 'وقد تختلف تكلفة الطلبية عمّا كانت، لأن الخصم الجديد يأكل طبقات اليوم';

    private const NOTHING_RETURNS = 'لا شيء يعود إلى المخزن: هذه الطلبية ليس عليها خصمٌ قائم';

    private const NOTHING_REDEDUCTS = 'لا شيء يُخصم من المخزن: حذف هذه الطلبية لم يُعِد إليه شيئاً';

    /** What the Libyan dinar is called beside a figure, as `PurchaseOrderCannotBeFunded` says it. */
    private const CURRENCY = 'د.ل';

    /**
     * **Named in the order's own words, not the enum's.** {@see OrderPaymentType::label()} says
     * «دفعة», «شطب فرق», «سُدِّدت لدى الناقل» — right for a row in a ledger, where the reader is
     * looking at one entry. This line is a *total* standing in a warning, and §٧٫١ names the
     * three it wants: «مدفوع», «إعفاء», «تحصيل مندوب». Reusing the enum's labels would have made
     * the confirmation read as a list of documents rather than a list of amounts.
     *
     * The order of the keys is the order of the lines, and it is the server's decision for the
     * same reason the order of the two sections is.
     */
    private const MONEY_LABELS = [
        OrderPaymentType::Payment->value => 'مدفوع',
        OrderPaymentType::WriteOff->value => 'إعفاء',
        OrderPaymentType::CarrierSettled->value => 'تحصيل مندوب',
    ];

    /**
     * @return array{money: MoneySection|null, stock: StockSection}
     */
    public static function for(Order $order): array
    {
        // Money first, literally: this array's key order is what reaches the screen, and §٧٫١
        // made that the server's call rather than Dart's.
        return $order->trashed()
            ? ['money' => self::reversedMoney($order), 'stock' => self::restorePreview($order)]
            : ['money' => self::moneyToReverse($order), 'stock' => self::deletePreview($order)];
    }

    /**
     * What {@see DeleteOrder} would reverse: one line per kind, summed, or nothing at all.
     *
     * **Read from {@see Order::liveCreditEntries()}, the delete's own query**, so the figure here
     * is arithmetically the same figure that will be written. Summing `paid_amount` and its two
     * sisters would have been one query fewer and wrong in the one case that matters: those
     * columns net an entry against its reversal, so an order carrying a payment somebody already
     * reversed reads as zero — and the delete, which walks rows rather than totals, agrees. It is
     * the *other* direction that breaks: a column reading zero cannot say **which** kinds are
     * live, and this section is a list of kinds.
     *
     * @return MoneySection|null
     */
    private static function moneyToReverse(Order $order): ?array
    {
        $totals = [];

        foreach ($order->liveCreditEntries() as $entry) {
            $key = $entry->type->value;
            $totals[$key] = Money::sum($totals[$key] ?? '0', (string) $entry->amount);
        }

        if ($totals === []) {
            return null;
        }

        return [
            'kind' => 'reverse',
            'warning' => self::REVERSE_HEADLINE,
            'lines' => self::moneyLines($totals),
            'note' => self::REVERSE_NOTE,
        ];
    }

    /**
     * The restore's half of §٧٫١: a line saying the reversed payments are not coming back.
     *
     * **Shown only when this order actually carries a reversal**, so an archived order that never
     * had a dinar against it is not warned about money it never took. Asked of the ledger rather
     * than of a new column: a reversal standing on an archived order means money was undone on
     * it, and whether the delete or a person at the counter undid it makes no difference to the
     * sentence — neither one comes back on a restore.
     *
     * No lines. The amounts belong to the confirmation that *did* the reversing, where they could
     * still change what somebody decided; here they would only be a bill for a decision already
     * taken.
     *
     * @return MoneySection|null
     */
    private static function reversedMoney(Order $order): ?array
    {
        $reversed = $order->payments()
            ->where('type', OrderPaymentType::Reversal->value)
            ->exists();

        return $reversed
            ? ['kind' => 'reversed', 'warning' => self::NOT_RESTORED, 'lines' => [], 'note' => null]
            : null;
    }

    /**
     * What {@see DeleteOrder} would hand back: exactly the lines whose draw still stands.
     *
     * @return StockSection
     */
    private static function deletePreview(Order $order): array
    {
        $drawn = $order->linesWithStockStillDrawn();

        return $drawn->isEmpty()
            ? self::nothingMoves(self::NOTHING_RETURNS)
            : [
                'kind' => 'return',
                'warning' => self::RETURN_HEADLINE,
                'lines' => self::lines($drawn),
                'note' => null,
            ];
    }

    /**
     * What {@see RestoreOrder} would take back out.
     *
     * **Every line, not only the ones the delete returned** — and that asymmetry is faithful
     * rather than sloppy: {@see DeductOrderStock} deducts the whole order when it runs, so a
     * preview naming a subset would describe a movement that is not the one about to happen.
     * The gate above it is the same fact the restore branches on, `delete_returned_stock_at`: a
     * delete that returned nothing is restored without touching a shelf.
     *
     * @return StockSection
     */
    private static function restorePreview(Order $order): array
    {
        if (! $order->deleteReturnedStock()) {
            return self::nothingMoves(self::NOTHING_REDEDUCTS);
        }

        return [
            'kind' => 'rededuct',
            'warning' => self::REDEDUCT_HEADLINE,
            'lines' => self::lines($order->items()->get()),
            'note' => self::REDEDUCT_NOTE,
        ];
    }

    /**
     * The «لا شيء» answer, which carries a sentence rather than an empty warning.
     *
     * A blank string would leave the confirmation dialog with a heading and nothing under it,
     * and the reader could not tell «لا يوجد أثر على المخزن» from «لم يُحسب بعد».
     *
     * @return StockSection
     */
    private static function nothingMoves(string $warning): array
    {
        return ['kind' => 'none', 'warning' => $warning, 'lines' => [], 'note' => null];
    }

    /**
     * One line per kind of credit entry, in the order {@see MONEY_LABELS} declares.
     *
     * **Three parts, not one sentence**, for the reason the stock lines are three: the app lays a
     * figure out differently from the word beside it, and «مدفوع: ١٬٢٠٠ د.ل» pre-joined here
     * would arrive as a string Dart has to take apart to right-align. The amount is left at two
     * decimal places, which is what {@see Money} produces everywhere else in this application and
     * what `order_payments.amount` is cast to — the grouping separator and the Arabic-Indic
     * digits §٧٫١ writes are the client's own formatting, not a second money format invented on
     * the server.
     *
     * @param  array<string, string>  $totals  amount per {@see OrderPaymentType} value
     * @return list<MoneyLine>
     */
    private static function moneyLines(array $totals): array
    {
        $lines = [];

        foreach (self::MONEY_LABELS as $type => $label) {
            if (! isset($totals[$type])) {
                continue;
            }

            $lines[] = [
                'label' => $label,
                'amount' => Money::round($totals[$type]),
                'currency' => self::CURRENCY,
            ];
        }

        return $lines;
    }

    /**
     * **Named by the line's own snapshots and counted in the shelf's unit.** `product_name` and
     * `variant_label` are what the order was written with, so an archived order refers to its
     * goods by what they were called then rather than what the catalogue has since been renamed
     * to; `stockUnit()` is the unit the movement is actually recorded in, which on a line sold by
     * the piece and stocked by the kilo is not `pricing_unit`.
     *
     * **The product is named as well as the size, and that is a fix rather than a flourish.**
     * This used to emit the bare `variant_label`, so an order for paper bags and plastic bags in
     * the same size drew two rows both reading «25*35» — on the one screen this whole feature
     * exists to show. `OrderStockShortfall` names its sizes bare and is right to: it answers
     * «أيّ رفٍّ ناقص؟» about lines the reader is already looking at. This answers «ماذا سيتحرّك؟»
     * about a list nobody has seen, where two identical rows are indistinguishable.
     *
     * The three parts are kept apart rather than pre-joined into one string, because the app lays
     * the number out differently from the label — the same reason `StockShortfall` is a DTO and
     * not a sentence.
     *
     * `variant.stockItem` is loaded for the whole set here: `stockUnit()` walks it per line, and
     * `Model::shouldBeStrict()` turns a forgotten load into an exception outside production.
     *
     * @param  EloquentCollection<int, OrderItem>  $items
     * @return list<PreviewLine>
     */
    private static function lines(EloquentCollection $items): array
    {
        $items->loadMissing('variant.stockItem');

        return $items
            ->map(fn (OrderItem $item): array => [
                'label' => self::label($item),
                'quantity' => DecimalText::trim($item->producedQuantity()),
                'unit' => $item->stockUnit()->label(),
            ])
            ->values()
            ->all();
    }

    /** «كيس ورقي — 25*35», or the size alone on a line written before `product_name` was kept. */
    private static function label(OrderItem $item): string
    {
        $product = trim((string) $item->product_name);
        $size = (string) $item->variant_label;

        return $product === '' ? $size : "{$product} — {$size}";
    }
}
