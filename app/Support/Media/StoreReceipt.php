<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Domain\Customer\Actions\UploadCustomerDesign;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Shortage\Actions\RecordShortageSupply;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Writes a receipt (الواصل) to the media disk and describes what it wrote.
 *
 * **Two callers, one habit.** A customer's payment proves itself with the paper they send us
 * ({@see RecordOrderPayment}); a sack bought to close a نقص proves itself with whatever the shop
 * next door wrote ({@see RecordShortageSupply}). Different rules about whether the paper is
 * *required* — the first demands it for a transfer, the second never does — but the same five
 * columns, the same private disk and the same refusal to let a client choose a path. The
 * directory is the caller's to name, which is the only thing that differs between them.
 *
 * Built on the same media layer as {@see UploadCustomerDesign}, with the same three habits and
 * for the same reasons:
 *
 * 1. **The disk and the path are returned, never a URL.** Moving to S3 stays a config change
 *    with no migration and no rewritten rows.
 * 2. **A generated filename.** Two customers sending `receipt.pdf` must not collide, and nobody
 *    gets to choose a path — a client-supplied name is a directory-traversal attempt waiting to
 *    be tried. Only the extension survives, and it is read off the sniffed bytes.
 * 3. **A sha256 of the bytes**, taken while the temporary copy is still readable. Unlike a
 *    design's, it is not a uniqueness key — one transfer legitimately covers two entries — but
 *    it answers "is this the same paper we were sent last time?" without opening either.
 *
 * **Not idempotent, deliberately**, which is the one place it parts from the design uploader.
 * A design is a thing the customer owns once; a receipt is evidence attached to a particular
 * entry, and two entries backed by the same transfer each need their own row pointing at it.
 *
 * The file is written inside the caller's transaction, so a payment refused for exceeding what
 * is outstanding leaves an object behind with no row. That costs storage and nothing else — the
 * reverse, a row pointing at a file that was never written, would be an entry whose proof cannot
 * be produced.
 */
final class StoreReceipt
{
    /**
     * @return array{
     *     receipt_disk: string,
     *     receipt_path: string,
     *     receipt_original_filename: string,
     *     receipt_size_bytes: int,
     *     receipt_checksum: string,
     * }
     */
    public function __invoke(string $directory, UploadedFile $file): array
    {
        $checksum = hash_file('sha256', $file->getRealPath());

        $disk = (string) config('media.payment_receipts.disk');

        // The extension the *bytes* answer to, never the one the client's filename claims —
        // a JPEG called `waseel.pdf` is stored as the JPEG it is. Validation has already
        // limited the answers to pdf and the image formats, so the fallback is unreachable
        // in practice and exists so a null could never write an extensionless path.
        $extension = $file->guessExtension() ?? 'bin';

        $path = $file->storeAs(
            $directory,
            Str::uuid()->toString().'.'.$extension,
            ['disk' => $disk],
        );

        return [
            'receipt_disk' => $disk,
            'receipt_path' => (string) $path,
            'receipt_original_filename' => (string) $file->getClientOriginalName(),
            'receipt_size_bytes' => (int) $file->getSize(),
            'receipt_checksum' => (string) $checksum,
        ];
    }
}
