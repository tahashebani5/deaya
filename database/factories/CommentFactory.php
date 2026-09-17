<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A customer by default, because that is what most tests are about. `->on($vendor)`
            // is one call away and says plainly which kind of record a test means.
            'commentable_type' => (new Customer)->getMorphClass(),
            'commentable_id' => Customer::factory(),
            // A colleague by default, so `->for($user)` in a test is what makes a note *mine*
            // and the difference between the two is never an accident of ordering.
            'user_id' => User::factory(),
            'body' => 'يفضّل التسليم صباحاً',
            'edited_at' => null,
        ];
    }

    /** Against this record, whatever kind it is. */
    public function on(Model $commentable): static
    {
        return $this->state(fn () => [
            'commentable_type' => $commentable->getMorphClass(),
            'commentable_id' => $commentable->getKey(),
        ]);
    }
}
