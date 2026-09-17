<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Customer\Models\CustomerDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerDesign
 */
class CustomerDesignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,

            // What staff read to tell two designs apart. Never null: the action falls back to
            // the filename, because a row with no name is one nobody dares print from.
            'label' => $this->displayName(),
            'notes' => $this->notes,

            // `image` or `pdf` — whether the app can draw this itself or must hand it to the
            // system viewer. Sent as a decided value rather than a mime type for the client to
            // interpret.
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'mime_type' => $this->mime_type,

            'original_filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'width_px' => $this->width_px,
            'height_px' => $this->height_px,

            // Generated per request from the disk the file actually lives on, and never stored.
            // On a private bucket it is a signed link that expires — a design is the customer's
            // property, and a permanent public URL is their print file in the open.
            'file_url' => $this->url(),

            // Reserved. A server-rendered first page for a PDF is a follow-up, and having the
            // key here now means it can land without an app release.
            'preview_url' => null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
