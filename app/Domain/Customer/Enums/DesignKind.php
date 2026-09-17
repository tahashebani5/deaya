<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

use InvalidArgumentException;

/**
 * What kind of file a design is — which decides whether the app can draw it or must hand it to
 * the system's own viewer.
 */
enum DesignKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';

    /**
     * Derived from the mime type **sniffed from the bytes**, never from the client's claim.
     *
     * Throws rather than defaulting. A default would file an unrecognised stranger under a kind
     * the app renders inline, and "render this unknown thing as an image" is how a surprise
     * becomes a crash on somebody's phone. Validation has already refused anything but these
     * four, so reaching the exception means validation and this enum have drifted apart — which
     * is worth a 500 rather than a silent guess.
     */
    public static function fromMimeType(string $mimeType): self
    {
        return match ($mimeType) {
            'application/pdf' => self::Pdf,
            'image/jpeg', 'image/png', 'image/webp' => self::Image,
            default => throw new InvalidArgumentException("نوع ملف غير مدعوم: {$mimeType}"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'صورة',
            self::Pdf => 'PDF',
        };
    }
}
