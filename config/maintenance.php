<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health thresholds
    |--------------------------------------------------------------------------
    |
    | What `maintenance:health` treats as a fault.
    |
    | The check looks at outcomes rather than at whether a job ran. "Did the sweep
    | execute" is answerable only while the machinery reporting it is itself working,
    | and it says nothing about a sweep that ran and silently decided nothing. "Is a
    | seller still blocked hours after their review window closed" is answerable from
    | the data alone, and it is true whether the cause was a stopped scheduler, a dead
    | worker, a failing job, or a bug in the matrix. The second question is the one
    | worth asking, because it describes the harm rather than one of its causes.
    |
    | Every figure below is a grace period on top of how often the work runs, not the
    | interval itself. The sweep runs hourly, so a proposal an hour past its window is
    | ordinary and one three hours past is not.
    |
    */

    'health' => [

        /*
        |----------------------------------------------------------------------
        | Blocked sellers
        |----------------------------------------------------------------------
        |
        | How many hours a proposal may sit pending past the close of its review
        | window before it counts as a fault.
        |
        | This is the one that matters. A proposal past its window that has not
        | resolved is a seller who cannot sell that product and has no route out,
        | because escalation is the route and escalation is what has not happened.
        |
        */

        'proposal_overdue_hours' => (int) env('HEALTH_PROPOSAL_OVERDUE_HOURS', 3),

        /*
        |----------------------------------------------------------------------
        | Orphaned verification photographs
        |----------------------------------------------------------------------
        |
        | How old a leftover photograph may be before it counts as a fault.
        |
        | Well beyond the cleanup's own six hour threshold and its daily schedule,
        | so an ordinary file waiting for tonight's run is not reported. A photograph
        | still present after this long means the cleanup is not running, and
        | invariant 7 is the thing at stake.
        |
        */

        'orphan_photograph_hours' => (int) env('HEALTH_ORPHAN_PHOTOGRAPH_HOURS', 36),

        /*
        |----------------------------------------------------------------------
        | Notification address
        |----------------------------------------------------------------------
        |
        | Where a failing check is emailed. Null sends to every administrator
        | account instead, which is the sensible default for a platform whose
        | administrators are already a known set of users.
        |
        | Email, because invariant 10 says notifications are email only and an
        | operations alert is still a notification.
        |
        */

        'notify' => env('HEALTH_NOTIFY_EMAIL'),

    ],

];
