<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Audit\AuditAttributeLabels;
use App\Domain\Audit\AuditReferenceNames;
use App\Domain\Audit\AuditValueLabels;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of history: who changed what, when, and from what to what.
 *
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * The names behind this page's foreign keys, looked up once for the whole page.
     *
     * Null until {@see ActivityLogCollection} hands them over — an entry rendered on its own
     * resolves nothing and prints raw ids, which is the same fallback every other missing label
     * takes here.
     */
    private ?AuditReferenceNames $referenceNames = null;

    /**
     * A page of these, with its foreign keys resolved in one query per kind.
     *
     * Overridden so both call sites — the global feed and every `/{resource}/{id}/logs` — get
     * the batching without knowing it exists.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource): ActivityLogCollection
    {
        return new ActivityLogCollection($resource);
    }

    public function withReferenceNames(AuditReferenceNames $names): self
    {
        $this->referenceNames = $names;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = AuditEvent::tryFrom((string) $this->event);
        $subject = AuditSubject::tryFrom((string) $this->subject_type);

        $valueLabels = AuditValueLabels::forChanges(
            $subject,
            $this->attribute_changes?->get('old'),
            $this->attribute_changes?->get('attributes'),
            $this->referenceNames,
        );
        $propertyLabels = AuditValueLabels::forProperties($this->properties);

        return [
            'id' => $this->id,

            // Both the value and its Arabic label, the same bargain every enum in this API
            // makes: the client renders the label and branches on the value, and never keeps
            // its own translation table in step with ours.
            'event' => $this->event,
            'event_label' => $event?->label(),

            // The sentence stored when it happened, not one composed now. History that rewords
            // itself when the code changes is not history.
            'description' => $this->description,

            // A stable alias — 'product' — never a PHP class name. See AuditSubject.
            'subject_type' => $this->subject_type,
            'subject_type_label' => $subject?->label(),
            'subject_id' => $this->subject_id,

            // Null for anything no person did: a seeder, a console command, a queued job.
            // Saying so explicitly beats an empty object the client has to interpret.
            'causer' => $this->whenLoaded('causer', fn () => $this->causerSummary()),

            // What actually moved. `old` is absent on a creation and `attributes` on a
            // deletion, because there is no such half in either case.
            'changes' => [
                'old' => $this->attribute_changes?->get('old'),
                'attributes' => $this->attribute_changes?->get('attributes'),
            ],

            // What to call each of those columns in Arabic — «رابط الصفحة», not `page_url`.
            //
            // Sent with the entry rather than kept in the app, and that is the whole point: a
            // dictionary on the phone is wrong the first morning somebody adds a column, with
            // no build failing to say so. Only the columns this entry actually touched, cast to
            // an object so an entry that touched none is `{}` and not `[]`.
            //
            // A column with no label is simply absent; the client falls back to the raw name.
            'attribute_labels' => (object) AuditAttributeLabels::forAttributes(
                $subject,
                $this->changedAttributeNames(),
            ),

            // And what each of those values *says* — «قيد الطباعة», not `printing`.
            //
            // Mirrors `changes` key for key, so the client reads
            // `value_labels[half][column] ?? changes[half][column]` and falls back to the raw
            // value exactly as it falls back to the raw column name above. Only what needed
            // translating is here: a name is already Arabic, a total is a number, and «نعم/لا»
            // the app says for itself.
            'value_labels' => [
                'old' => (object) $valueLabels['old'],
                'attributes' => (object) $valueLabels['attributes'],
            ],

            // Anything recorded by hand rather than by a model event — today, the permissions a
            // role gained or lost. Omitted entirely when there is none.
            'properties' => $this->when(
                $this->properties !== null && $this->properties->isNotEmpty(),
                fn () => $this->properties,
            ),

            // The Arabic for what is inside them — «عرض المنتجات», not `products.view`. Keyed by
            // the value itself, which is unambiguous for a permission in a way `12` never is for
            // a foreign key.
            'property_labels' => $this->when(
                $propertyLabels !== [],
                fn () => $propertyLabels,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Every column this entry touched, from whichever half of the change carries it.
     *
     * Both halves, not just `attributes`: a deletion records only `old`, and its columns need
     * naming as much as a creation's do.
     *
     * @return list<string>
     */
    private function changedAttributeNames(): array
    {
        $changes = $this->attribute_changes;

        if ($changes === null) {
            return [];
        }

        $old = $changes->get('old');
        $new = $changes->get('attributes');

        return array_values(array_unique(array_merge(
            is_array($old) ? array_keys($old) : [],
            is_array($new) ? array_keys($new) : [],
        )));
    }

    /**
     * Just enough to name the person: a history screen shows who, and links elsewhere for the
     * rest. A deleted user still resolves — `include_soft_deleted_subjects` and the fact that
     * users are soft deleted mean the trail never loses the name attached to a change.
     *
     * @return array<string, mixed>|null
     */
    private function causerSummary(): ?array
    {
        $causer = $this->causer;

        if ($causer === null) {
            return null;
        }

        return [
            'id' => $causer->getKey(),
            'type' => $causer->getMorphClass(),
            'name' => $causer instanceof User ? $causer->name : null,
        ];
    }
}
