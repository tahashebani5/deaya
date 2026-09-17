<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * The four things that can happen to a record.
 *
 * Mirrors Spatie's {@see ActivityEvent} rather than reusing it, so the vocabulary a client
 * filters on is ours: the package could rename or add a case tomorrow, and a value already
 * written into thousands of rows and hard-coded into a mobile app cannot follow it.
 *
 * `restored` exists only because every model soft deletes. A schema that deleted for real
 * could never produce this event, which is half the reason it does not.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'إنشاء',
            self::Updated => 'تعديل',
            self::Deleted => 'حذف',
            self::Restored => 'استرجاع',
        };
    }

    /**
     * The stored description — "تم إنشاء منتج", or just "تم الإنشاء" for a subject with no
     * registered label.
     */
    public function sentence(?string $subjectLabel = null): string
    {
        return $subjectLabel === null
            ? 'تم '.$this->definiteLabel()
            : 'تم '.$this->label().' '.$subjectLabel;
    }

    private function definiteLabel(): string
    {
        return match ($this) {
            self::Created => 'الإنشاء',
            self::Updated => 'التعديل',
            self::Deleted => 'الحذف',
            self::Restored => 'الاسترجاع',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }
}
