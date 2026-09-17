<?php

use App\Domain\Audit\Models\ActivityLog;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
     * The kill switch. Off, no activity is recorded at all — which is why it is on everywhere
     * except where a bulk import would otherwise write a million entries nobody will read.
     * Never turn it off in production to make something faster.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * `activitylog:clean` deletes entries older than this. Nothing runs it on a schedule yet,
     * and that is deliberate: an audit trail is not a cache, and the day it is pruned should be
     * a decision the business makes rather than one a default made for it.
     */
    'clean_after_days' => 365,

    /*
     * Only used by the activity() helper for entries written by hand. Every model-driven entry
     * names its own log after the subject — 'product', 'city' … — see the Auditable trait.
     */
    'default_log_name' => 'default',

    /*
     * Null means "whatever guard the request authenticated with", which is what we want: the
     * causer is resolved from Sanctum on an API call, and is null for a seeder or a console
     * command, which is the honest answer for work no person did.
     */
    'default_auth_driver' => null,

    /*
     * True, and it has to be: every model here soft deletes, so the subject of a `deleted`
     * entry is *always* trashed. Left false, the one entry a reader most wants to expand would
     * be the one whose subject resolves to null.
     */
    'include_soft_deleted_subjects' => true,

    /*
     * Our own model, so the Audit context owns the queries it needs and nothing else in the
     * application imports a vendor class to read its own history.
     */
    'activity_model' => ActivityLog::class,

    /*
     * Stripped from every entry, for every model, before it is written.
     *
     * The Auditable trait logs all attributes on purpose — a policy you have to remember to
     * write is one that gets forgotten. This list is what makes that safe: a secret cannot end
     * up in the trail because someone adding a model forgot to exclude it.
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
    ],

    /*
     * Off. Buffering trades an entry's id, and its survival if the process dies, for fewer
     * inserts. Our writes are a handful per request; the trade is not ours to make.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
