<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\BillboardData;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class CreateBillboard
{
    public function __construct(private readonly UploadBillboardImage $uploadImage) {}

    public function __invoke(BillboardData $data, UploadedFile $image, ?int $createdBy = null): Billboard
    {
        return DB::transaction(function () use ($data, $image, $createdBy): Billboard {
            $billboard = new Billboard([
                ...($this->uploadImage)($image),
                'title' => $data->title,
                'product_id' => $data->productId,
                'external_url' => $data->externalUrl,
                'sort_order' => $data->sortOrder ?? 0,
                'is_active' => $data->isActive ?? true,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
            ]);

            // Not fillable: who put a banner up is stamped from the signed-in user, never sent.
            $billboard->created_by = $createdBy;
            $billboard->save();

            return $billboard;
        });
    }
}
