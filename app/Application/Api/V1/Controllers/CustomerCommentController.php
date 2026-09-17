<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Comment\StoreCommentRequest;
use App\Application\Api\V1\Requests\Comment\UpdateCommentRequest;
use App\Domain\Audit\AuditService;
use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer comments
 *
 * What staff write to each other about a customer — «يفضّل التسليم صباحاً», «لا يردّ إلا على
 * واتساب». Things that are true of the person and have no field in the form, which would
 * otherwise be said out loud and leave with whoever heard them.
 *
 * **Writing one costs `customers.view` and nothing more.** A note is a working tool, not a
 * privilege: anyone who may look a customer up may leave the next person a sentence about them.
 *
 * **Changing one is «its author, or a moderator»** — `comments.moderate`, the one permission
 * covering notes on every kind of record. Each row carries `can_edit` and `can_delete` for the
 * reader asking, so the app draws the buttons without holding a second copy of the rule.
 *
 * Deleting is soft: the list loses the note, the history keeps it, and
 * `/comments/{comment}/logs` still answers who wrote what and who removed it.
 *
 * Everything here is four lines long: the behaviour is {@see CommentController}'s, and what this
 * class says is that these notes hang off a customer.
 */
class CustomerCommentController extends CommentController
{
    public function index(Customer $customer): JsonResponse
    {
        return $this->listFor($customer);
    }

    public function store(StoreCommentRequest $request, Customer $customer): JsonResponse
    {
        return $this->storeFor($request, $customer);
    }

    public function update(
        UpdateCommentRequest $request,
        Customer $customer,
        Comment $comment,
    ): JsonResponse {
        return $this->updateFor($request, $comment);
    }

    public function destroy(Request $request, Customer $customer, Comment $comment): JsonResponse
    {
        return $this->destroyFor($request, $comment);
    }

    public function logs(
        ActivityLogFilterRequest $request,
        Customer $customer,
        Comment $comment,
        AuditService $audit,
    ): JsonResponse {
        return $this->logsFor($request, $comment, $audit);
    }
}
