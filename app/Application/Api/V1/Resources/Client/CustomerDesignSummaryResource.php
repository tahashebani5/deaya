<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\CustomerDesignResource;
use App\Domain\Customer\Models\CustomerDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A design as its own owner sees it.
 *
 * **Two fields short of {@see CustomerDesignResource}, and both absences are the point.**
 *
 * `notes` is where staff write to each other about a piece of artwork — «العميل ما عجبه اللون،
 * خليه يعيد». It is the same category of text as a customer's comments, which
 * routes/api.php calls «what staff write to each other about them», and it is written in the
 * belief that the customer will not read it. So it does not leave for the app, in either
 * direction: this resource omits it and the client request refuses to set it.
 *
 * `customer_id` is gone because there is nothing to disambiguate — every row the customer app
 * ever receives is theirs, by construction, and echoing an id back invites a client to start
 * sending one.
 *
 * @mixin CustomerDesign
 */
class CustomerDesignSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Never null: the upload action falls back to the filename, because a design with no
            // name is one nobody dares print from.
            'label' => $this->displayName(),

            // `image` or `pdf` — whether the app can draw this itself or must hand it to the
            // system viewer. A decided value, not a mime type for the client to interpret.
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'mime_type' => $this->mime_type,

            'original_filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'width_px' => $this->width_px,
            'height_px' => $this->height_px,

            // Generated per request from the disk the file lives on, never stored. The designs
            // disk is private, so this is a signed link that expires — a permanent public URL
            // would be the customer's own print file left in the open.
            'file_url' => $this->url(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
