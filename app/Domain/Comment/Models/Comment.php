<?php

declare(strict_types=1);

namespace App\Domain\Comment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing a member of staff wrote about a record — a customer, a supplier.
 *
 * **`user_id` is not fillable, and that is the model's one rule.** A note is attributed, and
 * attribution is what makes it worth reading — «قال محمد إنه لا يردّ إلا على واتساب» can be
 * followed up; «مكتوب أنه لا يردّ» cannot. The author is stamped from the authenticated user
 * when the row is created and has no path to change afterwards, so a mass-assignment from a
 * request body can never sign somebody else's name to a sentence.
 *
 * **Its own domain rather than Customer's or Vendor's.** A note belongs to both and to whatever
 * comes next; putting it inside either would make the other depend on a namespace that has
 * nothing to do with it. See GENERAL-COMMENTS.md.
 *
 * Who may change one lives here rather than in the controller: the same question is asked twice
 * — once to refuse the request, once to tell the app whether to draw the button — and two copies
 * of an authorization rule is one copy too many. See {@see isChangeableBy()}.
 */
#[UseFactory(CommentFactory::class)]
#[Fillable(['body'])]
class Comment extends Model implements HasAuditTrail
{
    /** @use HasFactory<CommentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /**
     * Whatever this note is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Its own history and nothing else — a note owns no other record.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [$this->getMorphClass() => [$this->getKey()]];
    }

    /**
     * Whether this reader may rewrite or remove this note.
     *
     * **Its author, or somebody the business has explicitly made a moderator.** Deliberately not
     * `customers.manage` or `vendors.manage`: correcting a record and rewriting a colleague's
     * sentence under their name are different powers, and a business that wants to grant one
     * without the other has no way to say so if they share a permission.
     *
     * **One permission for every kind of note**, not one per record. The question it answers —
     * «هل يعدّل هذا الموظف كلام زميله؟» — is one question, and a moderator of a customer's notes
     * who was not trusted with a supplier's is a distinction nobody asked for. Decided
     * 14 August 2026; see GENERAL-COMMENTS.md §٣.
     *
     * The administrator passes through the gate in `AppServiceProvider`, which answers `true`
     * for every permission — so no case is needed for them here.
     */
    public function isChangeableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->getKey()
            || $user->can(PermissionName::ModerateComments->value);
    }
}
