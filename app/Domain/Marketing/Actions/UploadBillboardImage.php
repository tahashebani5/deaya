<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Puts a banner's picture on the media disk and answers the columns describing it.
 *
 * Its own action rather than a private method on {@see CreateBillboard}, because replacing a
 * banner's picture later is the same work — and because the *generated* filename is a security
 * decision, not a formatting one: the uploaded name is never used, so an attacker cannot choose
 * a path and two uploads called `promo.jpg` cannot collide.
 */
final class UploadBillboardImage
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(UploadedFile $file): array
    {
        $disk = (string) config('media.disk');

        $path = $file->storeAs(
            'billboards',
            Str::uuid()->toString().'.'.$file->extension(),
            ['disk' => $disk],
        );

        // `getimagesize` answers false rather than throwing for anything it cannot read, so the
        // dimensions stay null instead of failing an otherwise valid upload.
        $size = @getimagesize($file->getRealPath());

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'width_px' => $size === false ? null : $size[0],
            'height_px' => $size === false ? null : $size[1],
        ];
    }
}
