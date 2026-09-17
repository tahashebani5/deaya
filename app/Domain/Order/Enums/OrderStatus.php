<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\Actions\RequestOrder;

/**
 * Where an order is, and the only moves it may make from there.
 *
 * **This enum is the state machine.** The map in {@see allowedNext()} is the single definition
 * of what is legal; the API refuses anything else, the app draws its buttons from it, and the
 * permission each move costs is answered here too. Nothing else is allowed to hold an opinion
 * about what follows what — a second copy of these rules is a second thing to keep in step, and
 * the copy that drifts is always the one guarding the write.
 *
 * **The map reads two things about the order, and neither is a status.** {@see FulfilmentType}
 * decides which of the two dispatch statuses "it is leaving" means, and {@see OrderFlow} decides
 * whether the artwork and the press are on this order's road at all — a كيس سادة is picked off
 * a shelf, so «جديدة» leads straight to «جاهزة». Both are passed *in* rather than looked up:
 * this enum knows the rules and knows nothing about orders, customers or the catalogue, which is
 * what lets `OrderStatusTest` assert the whole machine without touching the database.
 *
 * **Adding a status is a `case` and a few lines in the matches below.** That is deliberately a
 * code change rather than a row in a table: half of these statuses carry behaviour a row cannot
 * express — dispatch is chosen by the server from the city, cancelling demands a reason, the
 * final two are closed, and each one costs a different permission. A `order_statuses` table
 * would offer the *appearance* of runtime extensibility while leaving all of that in PHP
 * anyway. `OrderStatusTest` walks every case and every pair, so an addition that forgets a
 * label, a permission, or a way in fails the build naming exactly what is missing.
 *
 * Order depends on Delivery and Identity; neither knows this enum exists.
 */
enum OrderStatus: string
{
    // ── the ones the workshop lives in, in the order the board reads them ────────────────────
    // **«بانتظار المراجعة» stands at the head, alone, in front of the pairs.** It is the only
    // card on this board that is not a piece of the workshop's work but a decision about whether
    // work begins at all, and its audience is whoever reviews what the app sent rather than
    // whoever is at a bench. So it is drawn full width above the grid rather than paired with
    // «جديدة» — pairing the two would say «هذا وذاك نوعان من العمل», and one of them is not
    // work yet. The pairs below are unchanged.
    //
    // **Two cards to a row on the phone, and each row is a pair.** «جديدة»/«نواقص» is what came
    // in beside what could not be started; «انتظار العربون»/«عربون مدفوع» is the money the job
    // waits on beside the money that arrived; «قيد التصميم»/«جاهزة للطباعة» is the artwork beside
    // the queue it feeds; «قيد الطباعة»/«قيد التصنيع» is our press beside the vendor's bench.
    // The deposit pair sits third rather than first because it is a road most orders never walk
    // — a board opens on what the shop has, and «جديدة» is what the shop has. That is the shop
    // asking «ما الذي عندي الآن؟», and it is not the order the state machine walks — «جاهزة
    // للطباعة» comes *before* «قيد التصميم» in {@see allowedNext()} and after it here. Both are
    // right: one answers what may follow what, the other answers what a person reads down a
    // phone. The app holds no list of its own, so this sequence is the board — see
    // `HomeSummaryResource`.

    /**
     * A customer placed this from the app, and nobody has looked at it yet.
     *
     * **The only status no member of staff can move an order into**, and the only one an order
     * can be created in without a member of staff. Nothing in {@see allowedNext()} leads here:
     * the way in is {@see RequestOrder}, called by the customer
     * API and by nothing else.
     *
     * It exists because «جديدة» means *verified*. An order a clerk types in carries an invisible
     * check — a person spoke to the customer before writing it down — and from «جديدة» the next
     * move is «جاهزة للطباعة», which takes the goods off the shelf. An order that arrived at 2am
     * from a phone carries no such check, and landing it in «جديدة» would put it in the same
     * column of the same board as one that does.
     *
     * Two ways out and no others: accepted, at which point it becomes an ordinary «جديدة» and
     * everything downstream is untouched; or refused, with a reason, like any other write-off.
     */
    case Requested = 'requested';

    /**
     * Taken, not started.
     *
     * **No longer the status nothing leads back to** — «بانتظار المراجعة» leads here, and it is
     * the only thing that does. What has not changed is that nothing leads back to it from
     * *inside* the workshop: every arm above it is still one-way, and an order that reaches
     * «جديدة» has been verified by somebody whichever door it came through.
     */
    case New = 'new';

    /**
     * The stock to start the job is not there. Reachable from «جديدة» and nowhere else — it
     * describes an order that could not be begun, not a run that came out short.
     */
    case Shortage = 'shortage';

    /**
     * The order is waiting on its عربون — the part-payment the shop asks for before it starts.
     *
     * **A commercial gate, not a step of the work**, which is why it sits between «جديدة» and
     * everything that costs money to do. Entering it names a figure and the way it is expected
     * to arrive; neither is money that has moved, and neither writes anything to the ledger —
     * see `TransitionFields` and Docs/orders/ORDER-DEPOSIT-PLAN.md §٣٫٢.
     *
     * **Optional, and reached from «جديدة» alone.** An order nobody asks a deposit for never
     * comes near this status: «جديدة» keeps every arm it had. A deposit demanded halfway through
     * printing is a different conversation from this one, and not one the map offers.
     */
    case AwaitingDeposit = 'awaiting_deposit';

    /**
     * The عربون has been paid — *said* to have been paid, by whoever moved the order here.
     *
     * **A claim, and deliberately a cheap one to make.** The move demands no payment: the clerk
     * at the counter is told the money is in and needs the job to start, while the حوالة may
     * take two days to appear in an account somebody else watches. Whether it truly arrived is
     * `orders.is_deposit_received` — a second employee's tick, made whenever they have checked,
     * and gating nothing. See `ConfirmDepositReceipt`.
     *
     * **The ledger is still the ledger.** If money *is* taken at this moment it is recorded as an
     * ordinary payment through `RecordOrderPayment`, exactly as «تم الاستلام» does — there is no
     * such thing here as a deposit that counts differently from any other money.
     *
     * Leads to the same places «جديدة» does, because that is where the order was going before
     * the deposit stopped it — and back to «انتظار العربون», for the claim that turns out to be
     * wrong. That way back is the only move on this pair that touches the ledger.
     */
    case DepositPaid = 'deposit_paid';

    /** Artwork is being agreed with the customer — see {@see OrderDesignStatus}. */
    case Designing = 'designing';

    /**
     * Prepped by the warehouse and handed to the press.
     *
     * **The line between two departments, and the reason this status exists.** «جديدة» and
     * «نواقص» are inventory's — the goods are being found, counted and weighed — while «قيد
     * التصميم» and «قيد الطباعة» belong to printing. An order used to cross that line invisibly,
     * so the press had no queue to read and no moment marked the handover.
     *
     * **It is also where the stock leaves the warehouse**, which is what makes it more than a
     * label: entering it names a warehouse and a weight, and the shelf drops. «جاهزة» later
     * measures again and corrects the difference — see `RestateOrderStockDeduction`.
     */
    case ReadyToPrint = 'ready_to_print';

    case Printing = 'printing';

    /**
     * The job is with an outside vendor, being made — «قيد التصنيع».
     *
     * **Only on the وسيط road, and it is the whole of that road's work.** دعاية sells the goods
     * and somebody else makes them: there is nothing here to prepare, nothing to weigh and no
     * press of ours to queue for, so this status has no «جاهز للتصنيع» in front of it. Sending
     * the job out *is* the move. See OUTSOURCED-PRODUCTS.md.
     *
     * **Not «قيد الطباعة» wearing a different word.** One status, one word, wherever it is drawn
     * — a label that changed with the road would fork the chip, the filter, the audit dictionary
     * and the home board, all four of which read {@see label()}.
     */
    case Manufacturing = 'manufacturing';

    /** Finished and on the shelf, waiting to leave. */
    case Ready = 'ready';

    /** Waiting at one of our branches for the customer to collect. */
    case OfficePickup = 'office_pickup';

    case OutForDelivery = 'out_for_delivery';

    case ReturnedCourier = 'returned_courier';

    case ReturnedCarrier = 'returned_carrier';

    /** Physically back on our shelf. */
    case ReturnedOffice = 'returned_office';

    /** Going out a second time — off our shelf, or from the carrier's depot without coming back. */
    case Resend = 'resend';

    case Cancelled = 'cancelled';

    /**
     * A request from the app that the shop would not take.
     *
     * **Not «إلغاء تام», and the difference is not a name.** Cancelling writes off an order the
     * shop *accepted*: it reverses stock if any left, closes the shortages raised against it,
     * carries a `cancellation_reason` and lands in the write-off reporting. A request refused at
     * the door has none of that behind it — nothing was reserved, nothing was promised, and no
     * money was ever owed. Sending it down the cancellation road made every one of those
     * mechanisms run over an order they were not written for, and put refusals in the same
     * column as genuine write-offs so that both counts were wrong.
     *
     * **And it cost the wrong authority.** «إلغاء تام» is `orders.status.cancelled`, the grant
     * for writing off real money. Declining an order nobody has accepted is the reviewer's own
     * work, so this carries `ManageOrders` — the same grant that let them see the request in
     * the first place.
     *
     * **Reversible, unlike a cancellation.** It leads back to «بانتظار المراجعة» and nowhere
     * else. A mis-tap on the intake queue should not be permanent, and there is nothing to
     * unwind in going back: the order never left the door.
     */
    case RequestRejected = 'request_rejected';

    // ── the two that are over ────────────────────────────────────────────────────────────────
    // **Last, and last on purpose.** Everything above still needs somebody to do something; a
    // full board is skimmed past the finished work rather than through it. See the note at the
    // top of the cases for why this sequence is a reading order rather than the machine's.

    /**
     * The customer has it. Closed to editing, but not finished: the money it was sent out to
     * collect still has to come back — see {@see Settled}.
     */
    case Delivered = 'delivered';

    /** The money is agreed. The end of the road. */
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'بانتظار المراجعة',
            self::New => 'جديدة',
            self::AwaitingDeposit => 'انتظار العربون',
            self::DepositPaid => 'عربون مدفوع',
            self::ReadyToPrint => 'جاهزة للطباعة',
            self::Designing => 'قيد التصميم',
            self::Printing => 'قيد الطباعة',
            self::Manufacturing => 'قيد التصنيع',
            self::Ready => 'جاهزة',
            self::Shortage => 'نواقص',
            self::OfficePickup => 'استلام مكتب',
            self::OutForDelivery => 'جاري التوصيل',
            self::Delivered => 'تم الاستلام',
            self::Settled => 'تم التسوية',
            self::ReturnedCourier => 'راجع لدى المندوب',
            self::ReturnedCarrier => 'راجع لدى شركة التوصيل',
            self::ReturnedOffice => 'راجع مكتب',
            self::Resend => 'إعادة إرسال',
            // «إلغاء تام», not «ملغاة»: the workshop calls off an order in more than one way —
            // a return comes back and can go out again, a resend is a second attempt — and the
            // one word for the ending that is not coming back has to say so. This label is what
            // the app prints on a chip, in a filter and on a timeline. One status, one word,
            // wherever it is drawn.
            self::Cancelled => 'إلغاء تام',
            self::RequestRejected => 'رُفض الطلب',
        };
    }

    /**
     * Every move this status may make. The map, and the reason this file exists.
     *
     * Going *backwards* is legal and listed explicitly rather than allowed wholesale: printing
     * returns to designing because staff mistype things and because artwork gets corrected. What
     * is not listed cannot happen — an unrestricted "you may go anywhere" would make the machine
     * decorative.
     *
     * **The line it stops at is «جاهزة».** Once the bags exist there is nothing to rewind to:
     * every move after that describes where a physical parcel is.
     *
     * **[$flow] is the second thing the map reads, and each road answers for itself.** An order
     * made entirely of goods that are not printed has no artwork to agree and no press to run, so
     * «جديدة» offers «جاهزة» directly; an order a vendor makes for us is sent out instead of
     * printed, so «جديدة» offers «قيد التصنيع» — see {@see OrderFlow}. Everything past «جاهزة» is
     * the same road whatever is in the bags: a parcel comes back from a courier the same way
     * whether it was printed, pulled off a shelf, or made by somebody else.
     *
     * **The default is not a convenience.** `Standard` is what every order in the system was
     * before this parameter existed, so a caller that does not know about flows — a console
     * command, `OrderStatusTest`'s structural sweeps — keeps getting exactly the map it always
     * got, and a new road is something a caller opts into by naming it.
     *
     * @return list<self>
     */
    public function allowedNext(OrderFlow $flow = OrderFlow::Standard): array
    {
        // **A `match` over the roads rather than an `if` over a boolean, and «وسيط» is why.**
        // The question used to be «هل يوجد إنتاج؟», which two roads could answer between them.
        // The third has a designer's queue on it and no press at all, so there is no one word
        // left to branch on — each road names its own arms, and shares the rest by falling
        // through to the standard map.
        return match ($flow) {
            OrderFlow::Standard => $this->standardNext(),
            OrderFlow::NoProduction => $this->noProductionNext(),
            OrderFlow::Outsourced => $this->outsourcedNext(),
        };
    }

    /**
     * Goods that are already made: nothing is designed, nothing is printed, and «جديدة» leads
     * straight to the shelf.
     *
     * **Both arms that differ are about the same absent thing: the work.** «جديدة» is where an
     * order is sent to the designer or the press; «نواقص» is where it goes when it could not be
     * started and is where it rejoins from. Neither has anything to offer an order whose goods
     * are already made, and offering it anyway would put two buttons on the screen that describe
     * work nobody is going to do.
     *
     * @return list<self>
     */
    private function noProductionNext(): array
    {
        return match ($this) {
            // Straight to the shelf. «نواقص» stays, and it is not an oversight: the stock for a
            // plain bag is exactly the thing that can turn out not to be there, which is what
            // that status has always meant — see the arm below.
            //
            // **The deposit is on every road**, unlike the two production statuses: asking for
            // money up front is about the deal, not about whether anything gets printed.
            self::New => [self::AwaitingDeposit, self::Ready, self::Shortage],

            // Where «جديدة» leads on this road, plus the ending. «انتظار العربون» itself needs no
            // arm here — it offers the same two moves whatever the order is made of, so it falls
            // through to the standard map.
            self::DepositPaid => [
                self::AwaitingDeposit, self::Ready, self::Shortage, self::Cancelled,
            ],

            // The way back on is «جاهزة» rather than the two production statuses, because those
            // are not on this order's road at all. Written as its own arm rather than as a filter
            // over the standard one: a filter would silently produce an empty list the day a
            // status is added, and an order with no way out of a shortage is the exact gap
            // `test_a_shortage_is_never_a_dead_end` exists to catch.
            self::Shortage => [self::Ready, self::Cancelled],

            default => $this->standardNext(),
        };
    }

    /**
     * The وسيط road: designed here if it needs designing, made by somebody else, back on our
     * shelf when the vendor is done.
     *
     * @return list<self>
     */
    private function outsourcedNext(): array
    {
        return match ($this) {
            // **Two ways out, and no «جاهزة للطباعة» between them.** That status is the door into
            // *our* press and the moment *our* warehouse lets the goods go, and neither happens
            // on this road — the business named its absence as a requirement. So the job either
            // goes to the designer first, or straight out to the vendor.
            //
            // **«نواقص» is absent too**, and for the plainer reason: we hold no stock of a وسيط
            // product, so there is nothing to be short of. The vendor being slow is «قيد
            // التصنيع» taking a while, not a shortage.
            //
            // **«انتظار العربون» is here too**, and it is if anything the road that wants it
            // most: a وسيط job is money paid to somebody else before any of it comes back.
            self::New => [self::AwaitingDeposit, self::Designing, self::Manufacturing],

            // The two ways «جديدة» offers on this road, plus the ending.
            self::DepositPaid => [
                self::AwaitingDeposit, self::Designing, self::Manufacturing, self::Cancelled,
            ],

            // Out of the designer's queue there is one place to go: the vendor.
            self::Designing => [self::Manufacturing, self::Cancelled],

            // Back to design for a correction, exactly as «قيد الطباعة» already allows — the
            // vendor sends a proof back and the logo moves. Forward is the finished job.
            self::Manufacturing => [self::Ready, self::Designing, self::Cancelled],

            // **Unreachable, and answered anyway.** Nothing on this road offers «نواقص», but a
            // status that could be *stood in* with no way out is the dead end
            // `test_a_shortage_is_never_a_dead_end` exists to catch — and falling through to the
            // standard arm would answer «جاهزة للطباعة», the one status this road denies.
            self::Shortage => [self::Manufacturing, self::Cancelled],

            default => $this->standardNext(),
        };
    }

    /**
     * The map as the business first described it, and still the one almost every order walks.
     *
     * Split out from {@see allowedNext()} so the two roads share the eleven arms they agree on
     * rather than keeping two copies of them — the day «راجع مكتب» gains a move, it gains it for
     * printed and plain orders alike, from one place.
     *
     * @return list<self>
     */
    private function standardNext(): array
    {
        return match ($this) {
            // The one open status that cannot be cancelled, and it is deliberate: a job nobody
            // has started is two taps from being started, and cancelling would compete with the
            // moves that matter. Cancelling is still available from every status after this one
            // — the order has cost something by then, which is when writing it off is a decision
            // rather than a stray tap. «نواقص» is here for the opposite reason: it is not an
            // ending, it is the job failing to start.
            //
            // **«انتظار العربون» is offered beside the work rather than in front of it.** Most
            // orders are started without one, so making the deposit compulsory would put a hop
            // through an empty figure on every order in the shop. The clerk takes the road that
            // matches the deal they made.
            // **Accepted, or refused.** Nothing else: a request under review has no deposit to
            // ask for, no shortage to discover and no press to queue for, because none of that
            // has been agreed with anybody yet. Accepting makes it «جديدة» and every arm below
            // applies from there exactly as it always did.
            //
            // **Written here, on the standard road, and inherited by the other two.** Both
            // `noProductionNext()` and `outsourcedNext()` end in `default => $this->standardNext()`,
            // and that fallthrough is the right answer rather than a convenient one: which road
            // an order walks is decided from its lines by `ResolveOrderFlow`, and that runs only
            // once the order is «جديدة». A request has no road yet, so accepting one cannot
            // differ by road. Three copies of this arm would be three chances to disagree.
            // **Refused rather than cancelled.** See {@see RequestRejected}: an order nobody
            // accepted has no stock to reverse and no money to write off, and «إلغاء تام»
            // would run all of that machinery over it and file it with the write-offs.
            self::Requested => [self::New, self::RequestRejected],

            // Back to the queue it came from, and nowhere else. A refusal is undoable because
            // nothing happened — which is exactly what separates it from a cancellation.
            self::RequestRejected => [self::Requested],

            self::New => [self::AwaitingDeposit, self::ReadyToPrint, self::Shortage],

            // Waiting on money: it arrives, or the order is written off. Nothing else can happen
            // to an order the shop has decided not to start — and «إلغاء تام» is offered here
            // although «جديدة» refuses it, because by this point the customer has been asked for
            // money and calling that off is a decision somebody took rather than a stray tap.
            self::AwaitingDeposit => [self::DepositPaid, self::Cancelled],

            // **Exactly where «جديدة» leads**, because that is where the order was going before
            // the deposit stopped it — the handover to the press, or the shortage that stops it
            // again. Written out rather than borrowed from the arm above so this status keeps
            // answering for itself, and so «إلغاء تام» can be on it while «جديدة» goes without.
            //
            // **And back, which is the one move on this pair that moves money.** A عربون declared
            // paid and then not there — the حوالة never landed, the customer changed their mind
            // — is an ordinary Tuesday, and the order goes back to waiting for it. The entry the
            // forward move wrote is reversed by `ChangeOrderStatus` in the same transaction, so
            // the ledger never carries a deposit the shop has stopped claiming. Nothing else on
            // this road rewinds; this does, because what it rewinds is a statement rather than a
            // piece of work.
            self::DepositPaid => [
                self::AwaitingDeposit, self::ReadyToPrint, self::Shortage, self::Cancelled,
            ],

            // **The handover, and the only way into the press.** «جديدة» used to lead straight to
            // the designer or the machine, which meant the moment inventory finished with an
            // order was recorded nowhere: the press found out by somebody noticing. Routing both
            // through one status gives printing a queue to read, and gives the warehouse one
            // place to weigh the goods and let them go — see {@see ReadyToPrint}.
            self::ReadyToPrint => [self::Designing, self::Printing, self::Cancelled],

            // Never straight to ready: artwork that has been agreed still has to be printed.
            self::Designing => [self::Printing, self::Cancelled],

            self::Printing => [self::Ready, self::Designing, self::Cancelled],

            // **Off this road, and answered anyway** — the mirror of «نواقص» on the وسيط road.
            // Nothing here offers «قيد التصنيع»: an order only reaches it by being made of goods
            // a vendor makes, and that order is not walking this map. But a status with no moves
            // reads as an ending, and the structural sweeps are right to refuse one — so the
            // answer is the one being-made always has, wherever the machine stood: forward to the
            // shelf, back to the artwork, or written off.
            self::Manufacturing => [self::Ready, self::Designing, self::Cancelled],

            // **Out, or written off. Nothing goes back from here.** «جاهزة» means the bags are
            // made, counted and on the shelf; the two ways out are the dispatch pair, and the
            // server picks which — see dispatchFor(). Returning to «قيد الطباعة» was offered for
            // a reprint and described the wrong event: bags that have to be printed again are a
            // *new* run, not this one rewinding, and the order's status would have gone on
            // saying the finished bags do not exist while they sat on the shelf.
            self::Ready => [self::OfficePickup, self::OutForDelivery, self::Cancelled],

            // **Only «جديدة» leads here, and that is the whole meaning of the status.** «نواقص»
            // is what the shop finds when it goes to start a job it has taken: the stock for one
            // of the sizes is not on the floor, so the order is parked before any work is done
            // on it. Offering the same status from «قيد الطباعة» and «جاهزة» made it a second
            // name for «the run came up short» — a different event, at a different desk, which
            // the press already answers by going back a step to print the rest.
            //
            // Out of it is the way back on to the road it was taken off, plus the ending: a
            // shortage that is never resolved is written off rather than left parked forever.
            // It rejoins at «جاهزة للطباعة» rather than at the two production statuses for the
            // same reason «جديدة» does — an order reaches the press through one door, and the
            // missing stock has to be found and weighed before it goes through it. The domain
            // refuses the move while anything is still short; see
            // `ShortageMustBeResolved`.
            self::Shortage => [self::ReadyToPrint, self::Cancelled],

            // No returns: it never left the building, so there is no courier and no carrier.
            // A customer who has not come yet is simply still waiting.
            self::OfficePickup => [self::Delivered, self::Cancelled],

            // **A return is a chain of custody, walked one link at a time.** The parcel is with
            // the courier, who hands it back to the company that sent him, who hands it back to
            // us — and each of those hand-overs is a real event somebody is answerable for.
            // Allowing «جاري التوصيل» to jump straight to «راجع مكتب» would let the system
            // record a parcel as being on our shelf while it is still in somebody's van.
            //
            // Cancelling is absent from all three for the same reason: an order is not written
            // off while it is physically outside the building. It comes home first, and
            // «راجع مكتب» is where that decision is taken.
            self::OutForDelivery => [self::Delivered, self::ReturnedCourier],

            self::ReturnedCourier => [self::ReturnedCarrier],

            // **Coming home is one link at a time; going out again is not.** A parcel sitting at
            // the delivery company's depot is most often waiting on nothing more than the
            // customer answering their phone, and the company then goes out with it a second
            // time — the van never comes to us. Making that trip pass through «راجع مكتب» would
            // have staff record the parcel onto a shelf it never reached in order to describe a
            // second attempt that was never interrupted, which is the same lie the chain exists
            // to prevent, told in the other direction. «راجع مكتب» stays for the parcel that
            // genuinely does come back.
            self::ReturnedCarrier => [self::ReturnedOffice, self::Resend],

            // Back on our shelf, and three real endings: the customer comes in for it, it goes
            // out again, or it is written off.
            self::ReturnedOffice => [self::Delivered, self::Resend, self::Cancelled],

            // A second attempt leaves exactly the way the first one did, and the destination
            // still decides which of the two that is.
            self::Resend => [self::OfficePickup, self::OutForDelivery, self::Cancelled],

            // Handing the bags over is not the end of the job: what was collected for them has
            // to come back and be agreed. Offered here whatever the order has been paid — the
            // map answers what *follows* what — and refused by the action while anything is
            // still owed, so the accountant is told what to record rather than left looking for
            // a button that vanished. See SettlementRequiresFullPayment.
            self::Delivered => [self::Settled],

            self::Settled, self::Cancelled => [],
        };
    }

    /**
     * **[$flow] must be passed by anything enforcing the map**, not merely by anything drawing
     * it. `ChangeOrderStatus` is the one caller that matters: left on the default it would refuse
     * the «جاهزة» the app had just been told it could offer, and the short road would exist on
     * screen and nowhere else.
     */
    public function canMoveTo(self $target, OrderFlow $flow = OrderFlow::Standard): bool
    {
        return in_array($target, $this->allowedNext($flow), true);
    }

    /** Finished. Nothing follows, and nothing may reopen it. */
    public function isFinal(): bool
    {
        return $this === self::Settled || $this === self::Cancelled;
    }

    /**
     * Whether the order itself is closed to editing.
     *
     * **Not the same question as {@see isFinal()}, and «تم الاستلام» is why.** The bags are with
     * the customer, so nothing about the order — its lines, its address, its price — may be
     * touched again; but the money it went out to collect has not been agreed yet, so the order
     * still has a move to make. One flag was asked to mean both and could only be right about
     * one of them.
     */
    public function isClosed(): bool
    {
        return $this === self::Delivered || $this->isFinal();
    }

    /** The order has left the workshop and is with — or waiting for — the customer. */
    public function isDispatch(): bool
    {
        return $this === self::OfficePickup || $this === self::OutForDelivery;
    }

    /**
     * Which of the two dispatch statuses a city implies.
     *
     * The clerk does not choose between them: they say "it is going out", and the destination
     * decides what that means. Keeping the decision here rather than in the request is what
     * stops an order for قرجي being marked out for delivery by a mistyped payload.
     *
     * The mapping lives in Order rather than on {@see FulfilmentType} so the delivery map stays
     * ignorant of orders — dependencies run one way.
     */
    public static function dispatchFor(FulfilmentType $type): self
    {
        return match ($type) {
            FulfilmentType::OfficePickup => self::OfficePickup,
            FulfilmentType::Delivery => self::OutForDelivery,
        };
    }

    /**
     * What a user must hold to move an order *into* this status.
     *
     * One permission per status, so the business composes roles — a designer, a printer, a
     * delivery coordinator — without any of that shape being baked into the code.
     *
     * **The two dispatch statuses share one.** They are the only pair the clerk does not choose
     * between, so splitting them would make the same button succeed for طرابلس and fail for
     * قرجي with nothing on screen to explain the difference. The three returns stay separate
     * precisely because a clerk *does* choose which one happened.
     *
     * `New` answers with the permission to create an order, since that is the only way in.
     */
    public function permission(): PermissionName
    {
        return match ($this) {
            // **Never actually consulted for an entry, because nothing leads here.** The map
            // offers no arm producing «بانتظار المراجعة», so `ChangeOrderStatus` refuses the
            // move before a permission is ever asked for; the case is answered because the enum
            // is total and a `match` without it is a runtime error waiting for the first person
            // to add a status. `orders.manage` is the honest answer regardless: creating an
            // order is what this status is a request for.
            self::Requested => PermissionName::ManageOrders,

            // Accepting a customer's request costs exactly what typing the order in by hand
            // costs, because that is what accepting it is.
            self::New => PermissionName::ManageOrders,
            // One grant each, like every status that is not the dispatch pair: asking a customer
            // for a deposit and declaring that they paid it are two different claims, and the
            // business composes who may make which.
            //
            // **Neither of them is the permission that confirms the money arrived.** That is
            // `orders.deposit.confirm`, held by somebody else and refused to whoever made the
            // claim — see ConfirmDepositReceipt.
            self::AwaitingDeposit => PermissionName::MoveOrderToAwaitingDeposit,
            self::DepositPaid => PermissionName::MoveOrderToDepositPaid,
            self::ReadyToPrint => PermissionName::MoveOrderToReadyToPrint,
            self::Designing => PermissionName::MoveOrderToDesigning,
            self::Printing => PermissionName::MoveOrderToPrinting,
            // Its own grant rather than the press's: sending a job to a vendor and chasing it is
            // a different desk from running a machine, and the business composes the two roles
            // however it likes — which is the whole reason there is one permission per status.
            self::Manufacturing => PermissionName::MoveOrderToManufacturing,
            self::Ready => PermissionName::MoveOrderToReady,
            self::Shortage => PermissionName::MoveOrderToShortage,
            self::OfficePickup, self::OutForDelivery => PermissionName::DispatchOrders,
            self::Delivered => PermissionName::MarkOrdersDelivered,
            self::Settled => PermissionName::SettleOrders,
            self::ReturnedCourier => PermissionName::RecordCourierReturn,
            self::ReturnedCarrier => PermissionName::RecordCarrierReturn,
            self::ReturnedOffice => PermissionName::RecordOfficeReturn,
            self::Resend => PermissionName::ResendOrders,
            self::Cancelled => PermissionName::CancelOrders,
            // **Not `CancelOrders`.** Declining a request is the reviewer's own work and costs
            // the grant that showed them the queue — see the case's docblock. A dedicated
            // permission would have shipped a button nobody could press until every role was
            // edited.
            self::RequestRejected => PermissionName::ManageOrders,
        };
    }

    /**
     * Whether the move must carry an explanation.
     *
     * Only writing an order off. Everything else is ordinary work whose reason is obvious from
     * the status itself, and demanding a sentence for each would produce a column full of "ok".
     */
    public function requiresReason(): bool
    {
        // Writing an order off, and refusing one. **The refusal is the customer's answer** —
        // «رُفض الطلب» with no sentence attached reaches their phone as a door closed without a
        // word, which is worse than the refusal itself.
        return $this === self::Cancelled || $this === self::RequestRejected;
    }

    /**
     * The column on `orders` stamped when this status is entered, if any.
     *
     * Denormalised on purpose: `order_status_transitions` holds the full history, but "orders
     * printed this week" should not have to walk it. Null where a column would be a lie or a
     * cost with nothing asking for it.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::New => 'placed_at',
            // «متى قيل إنّ العربون دُفع؟» is asked of every order whose deposit has not been
            // confirmed yet — it is the age of the claim, and what the accountant's queue is
            // ordered by. Re-entered after a walk-back it is overwritten, which is right: the
            // earlier claim was withdrawn, and the standing one is the one being asked about.
            self::DepositPaid => 'deposit_paid_at',
            // Null, like «إعادة إرسال» and for the same reason: an order can be sent back to
            // wait for its عربون more than once, and one column would keep the last visit and
            // quietly lose the first. `order_status_transitions` holds every visit, and nothing
            // yet asks a question a column would answer faster.
            self::AwaitingDeposit => null,
            // Unlike «نواقص», something does ask this question: «كم تقعد الطلبية بين المخزن
            // والمطبعة؟» is the whole reason the status was added, and it cannot be answered
            // from a status that is entered at most once without a column to read.
            self::ReadyToPrint => 'ready_to_print_at',
            self::Designing => 'design_started_at',
            self::Printing => 'printing_started_at',
            // «كم يوماً تقعد الطلبية عند المورد؟» is the first question this road will be asked,
            // and it is not answerable from `printing_started_at` — sharing the column would put
            // two different events under one name and lose which of them a date meant.
            self::Manufacturing => 'manufacturing_started_at',
            self::Ready => 'ready_at',
            self::OfficePickup, self::OutForDelivery => 'dispatched_at',
            self::Delivered => 'delivered_at',
            self::Settled => 'settled_at',
            self::ReturnedCourier, self::ReturnedCarrier, self::ReturnedOffice => 'returned_at',
            self::Cancelled => 'cancelled_at',
            self::RequestRejected => 'request_rejected_at',
            // A re-send is visited more than once by the orders that visit it at all — a parcel
            // goes out, comes back and goes out again — so a single column would keep the last
            // visit and quietly lose the first. A shortage is entered at most once now that
            // «جديدة» is its only way in, so a column *could* hold it honestly; it stays null
            // because nothing asks the question yet, and the transitions table already has the
            // answer. The day a report wants "orders parked short this week", this is a `case`
            // and a migration.
            self::Shortage, self::Resend => null,

            // **Null, and now reachable — which it was not before.** Nothing used to lead to
            // «بانتظار المراجعة»: an order was *created* there and left once. A refusal can be
            // undone now, so an order can enter it more than once, and a single column would
            // keep the last visit and quietly lose the first — the same reason «إعادة إرسال»
            // and «انتظار العربون» have none. `order_status_transitions` holds every visit.
            self::Requested => null,
        };
    }

    /**
     * The route an order is *meant* to take, in order.
     *
     * جديدة → قيد التصميم → قيد الطباعة → جاهزة → (التسليم) → تم الاستلام → تم التسوية. This is
     * the sequence the business described, and it is what a progress bar on the order screen
     * walks. The last step is money rather than bags, and it is on the line because an order
     * whose cash never came back is not a finished order.
     *
     * **Not every status is on it, and that is the point.** «نواقص»، الرواجع الثلاثة و«إعادة
     * إرسال» are detours — real, common, and off the line. Putting them in the sequence would
     * make the bar claim every order passes through a shortage on its way to being ready. They
     * are reported beside the line instead, as where the order actually is.
     *
     * The dispatch pair collapses to whichever the destination implies, so the line has one
     * step there rather than a fork the reader has to resolve.
     *
     * **An order that skips production has a shorter line, not a line with two dead steps on
     * it.** Leaving «قيد التصميم» and «قيد الطباعة» drawn-but-never-reached would make the bar
     * claim a plain order is two sevenths of the way through when it is on the shelf and ready
     * to go — the same lie the detour handling above exists to prevent, told about the road
     * instead of about the order.
     *
     * **Each road names its middle, rather than the long one being filtered down.** A filter
     * could say which steps to *remove*; it could not say that the وسيط road has a step the
     * others do not, and a line assembled by subtraction has no way to add «قيد التصنيع» back.
     * Both ends are shared, because every order is taken the same way and every order finishes
     * the same way.
     *
     * @return list<self>
     */
    public static function mainLine(FulfilmentType $fulfilment, OrderFlow $flow = OrderFlow::Standard): array
    {
        $work = match ($flow) {
            OrderFlow::Standard => [self::ReadyToPrint, self::Designing, self::Printing],
            // Nothing between being taken and being on the shelf: that is the road.
            OrderFlow::NoProduction => [],
            // Design is on this road and «جاهزة للطباعة» is not — the job is sent out, not
            // prepared for a press of ours.
            OrderFlow::Outsourced => [self::Designing, self::Manufacturing],
        };

        return [
            self::New,
            ...$work,
            self::Ready,
            self::dispatchFor($fulfilment),
            self::Delivered,
            self::Settled,
        ];
    }

    /**
     * Whether this status is the work itself — the artwork being agreed, or the press running.
     *
     * The two steps an order made of ready-made goods never takes, named once here so
     * {@see mainLine()} filters on a question about the domain rather than on a list of two
     * cases repeated wherever the short road is drawn.
     */
    /**
     * **«جاهزة للطباعة» counts as production**, and that is what keeps it off the blank-goods
     * line: it is the door into the press, so an order that never goes near the press never goes
     * near it either.
     *
     * **«قيد التصنيع» counts too, and it is work nobody here does.** The question this answers is
     * «هل هذه الحالة هي الشغل نفسه؟» — being made is being made, whether the machine is ours or a
     * vendor's. What it is *not* is a question about our press or our shelf: those are asked of
     * the road, by {@see mainLine()} and `OrderFlow::deductsStock()` respectively.
     */
    public function isProduction(): bool
    {
        return $this === self::ReadyToPrint
            || $this === self::Designing
            || $this === self::Printing
            || $this === self::Manufacturing;
    }

    /**
     * Whether this status sits on the main line at all.
     *
     * A detour — a shortage, a return, a cancellation — is somewhere an order genuinely is, but
     * it is not a step on the way to anywhere, and a progress bar that pretended otherwise would
     * be drawing a road that does not exist.
     */
    public function isOnMainLine(FulfilmentType $fulfilment, OrderFlow $flow = OrderFlow::Standard): bool
    {
        return in_array($this, self::mainLine($fulfilment, $flow), true);
    }

    /**
     * How far along the main line this status is, or null when it is a detour.
     */
    public function mainLinePosition(FulfilmentType $fulfilment, OrderFlow $flow = OrderFlow::Standard): ?int
    {
        $index = array_search($this, self::mainLine($fulfilment, $flow), true);

        return $index === false ? null : $index;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
