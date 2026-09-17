<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Comment\StoreCommentRequest;
use App\Application\Api\V1\Requests\Comment\UpdateCommentRequest;
use App\Domain\Audit\AuditService;
use App\Domain\Comment\Models\Comment;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vendor comments
 *
 * What staff write to each other about a supplier — «لا يسلّم قبل الظهر», «يرفع السعر إن طلبنا
 * أقل من طن», «المندوب الجديد اسمه سالم». The same thing {@see CustomerCommentController} serves
 * for a customer, on the record the shop buys from.
 *
 * **Writing one costs `vendors.view` and nothing more**, mirroring the customer's rule for the
 * same reason: knowing this about a supplier is part of doing the job, not a privilege above it.
 *
 * **Changing one is «its author, or a moderator»** — `comments.moderate`, one permission across
 * every kind of note. See {@see CommentController} for all four behaviours; this class only says
 * that these notes hang off a vendor.
 */
class VendorCommentController extends CommentController
{
    public function index(Vendor $vendor): JsonResponse
    {
        return $this->listFor($vendor);
    }

    public function store(StoreCommentRequest $request, Vendor $vendor): JsonResponse
    {
        return $this->storeFor($request, $vendor);
    }

    public function update(
        UpdateCommentRequest $request,
        Vendor $vendor,
        Comment $comment,
    ): JsonResponse {
        return $this->updateFor($request, $comment);
    }

    public function destroy(Request $request, Vendor $vendor, Comment $comment): JsonResponse
    {
        return $this->destroyFor($request, $comment);
    }

    public function logs(
        ActivityLogFilterRequest $request,
        Vendor $vendor,
        Comment $comment,
        AuditService $audit,
    ): JsonResponse {
        return $this->logsFor($request, $comment, $audit);
    }
}
