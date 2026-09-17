<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

/**
 * What somebody typed into the announcement form.
 *
 * The one place in this context where text crosses from a request into the domain, so it is the
 * one place that needs a DTO for it (RULES.md §3 — an array crosses exactly once, through
 * `fromArray()`, fed by already-validated request data).
 */
final readonly class AnnouncementData
{
    /**
     * @param  int|null  $roleId  null means «الجميع» — every active employee. A role id narrows
     *                            it to that role's members.
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?int $roleId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  already validated by the FormRequest
     */
    public static function fromArray(array $data): self
    {
        $roleId = $data['role_id'] ?? null;

        return new self(
            title: trim((string) $data['title']),
            body: trim((string) $data['body']),
            roleId: $roleId === null ? null : (int) $roleId,
        );
    }
}
