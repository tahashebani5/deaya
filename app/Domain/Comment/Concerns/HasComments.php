<?php

declare(strict_types=1);

namespace App\Domain\Comment\Concerns;

use App\Domain\Comment\Models\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record staff may leave notes on.
 *
 * One line on a model is the whole cost of joining in — which is the point of having generalised
 * the table at all. Today: the customer and the supplier. An order or a purchase order is a
 * `use` and two routes away, and deliberately not added before a screen wants it.
 *
 * @phpstan-require-extends Model
 */
trait HasComments
{
    /**
     * What staff have written to each other about this record.
     *
     * Newest first, and by `id` rather than `created_at`: two notes typed in the same second
     * would otherwise come back in whichever order the database felt like, and the list is read
     * top-down as a conversation.
     *
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->latest('id');
    }
}
