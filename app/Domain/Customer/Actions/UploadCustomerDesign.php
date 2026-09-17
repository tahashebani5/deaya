<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Catalog\Actions\UploadProductImage;
use App\Domain\Customer\Enums\DesignKind;
use App\Domain\Customer\Exceptions\TooManyCustomerDesigns;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores a customer's artwork and records it against them.
 *
 * Four deliberate departures from {@see UploadProductImage}, each forced by the fact that a
 * design may be a PDF and belongs to somebody else:
 *
 * 1. **The mime type is sniffed from the bytes**, not taken from `getClientMimeType()`. The
 *    product uploader stores the client's claim, which is harmless there because the `image`
 *    validation rule had already proved the content really was an image. That rule has to go
 *    here — it is exactly what rejects a PDF — and without it the client's claim would be the
 *    only record of what the file is, and it is attacker-controlled.
 * 2. **A sha256 is taken before the file is moved**, while the temporary copy is still readable.
 * 3. **The upload is idempotent on that checksum.** A dropped connection means the caller does
 *    not know whether the request landed, so it must retry — and a retry must not leave two
 *    copies of the same artwork. Finding the file already here answers with the existing row.
 * 4. **No transaction.** One insert, and no primary flag to juggle. The file is written first on
 *    purpose: a failed insert leaves an orphaned object, which costs storage, where the reverse
 *    leaves a row pointing at nothing — an order that cannot show what it printed.
 */
final class UploadCustomerDesign
{
    /**
     * @return array{0: CustomerDesign, 1: bool} the design, and whether it was created now
     */
    public function __invoke(
        Customer $customer,
        UploadedFile $file,
        ?string $label = null,
        ?string $notes = null,
    ): array {
        $checksum = hash_file('sha256', $file->getRealPath());

        // Answered before anything is written: a retry must cost nothing and change nothing.
        $existing = $customer->designs()->where('checksum', $checksum)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        $limit = (int) config('media.customer_designs.max_per_customer');
        if ($customer->designs()->count() >= $limit) {
            throw new TooManyCustomerDesigns($limit);
        }

        // Read from the file itself. `finfo` looks at the magic bytes; the client's header and
        // the extension are both things the uploader chose.
        $mimeType = (string) $file->getMimeType();
        $kind = DesignKind::fromMimeType($mimeType);

        $disk = (string) config('media.customer_designs.disk');

        // A generated name, not the uploaded one: two customers sending "logo.pdf" must not
        // collide, and nobody gets to choose a path.
        $path = $file->storeAs(
            "customer-designs/{$customer->getKey()}",
            Str::uuid()->toString().'.'.$file->extension(),
            ['disk' => $disk],
        );

        [$width, $height] = $this->dimensionsOf($file, $kind);

        $design = $customer->designs()->create([
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'kind' => $kind,
            'size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'width_px' => $width,
            'height_px' => $height,
            // Defaulted from the filename, because the label is the whole way staff tell two
            // designs apart — there are no PDF thumbnails — and an unnamed row is one nobody
            // dares print from.
            'label' => $label ?? pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME),
            'notes' => $notes,
        ]);

        return [$design, true];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensionsOf(UploadedFile $file, DesignKind $kind): array
    {
        // A PDF has pages, not pixels. `getimagesize` on one returns false anyway; skipping it
        // says so on purpose rather than by accident.
        if ($kind !== DesignKind::Image) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
