<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        /*
         * One threshold per queue, because the cost of a minute's delay is different on
         * each. Every queue is named explicitly, so a new one cannot inherit a figure
         * nobody chose for it.
         *
         * `default` is the tightest, and it is worth being clear why, since it is not
         * the queue carrying the most consequential work. It carries the AI jobs that
         * X-01 polls for, so a wait here is a person sitting in front of a spinner
         * wondering whether the platform is broken. A minute is already a long time to
         * be that person.
         *
         * `maintenance` carries the review window sweep, which is the most important
         * recurring work in the platform and is also scheduled hourly. A five minute
         * wait on hourly work means the queue is not being drained; a ninety second one
         * means nothing at all, and alarming on it would train somebody to ignore the
         * alert that matters. The consequence of the sweep never running is caught by
         * `maintenance:health` instead, which asks whether a seller is still blocked
         * rather than how long a job queued.
         *
         * `revalidation` is the loosest, because it is the only queue nobody is waiting
         * on. A late rebuild is a buyer reading last week's specifications, which is
         * worth fixing and is not worth waking anybody up for.
         */
        'redis:default' => 60,
        'redis:maintenance' => 300,
        'redis:revalidation' => 600,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        /*
         * Nothing is silenced.
         *
         * The obvious candidate is the revalidation job, which fires on every version
         * and always succeeds when the client is up. It is left visible on purpose:
         * "did the page rebuild after that edit" is the exact question somebody asks
         * when a product page looks wrong, and the completed jobs list is where the
         * answer is.
         */
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        /*
         * Two supervisors rather than one, and the split is the whole point.
         *
         * A single supervisor over all three queues would let a batch of revalidation
         * attempts against an unreachable client occupy every process, and the review
         * window sweep would queue behind them. Sellers waiting to be unblocked must
         * never wait on a cache invalidation, so the work that people are blocked on
         * gets processes that nothing else can take.
         */
        'supervisor-platform' => [
            'connection' => 'redis',
            /*
             * Ordered by urgency, and Horizon honours that order: `maintenance` is
             * drained before `default`. The sweep is the only work here that somebody
             * is blocked on, so it goes first even when a hundred AI jobs are waiting.
             */
            'queue' => ['maintenance', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            /*
             * One try at this level. Every job here declares its own `tries`, and a
             * supervisor default above one would silently retry the jobs that
             * deliberately do not want retrying, the sweep among them.
             */
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],

        /*
         * Revalidation on its own processes.
         *
         * It is the only work in the platform that waits on an external HTTP service,
         * so it is the only work that can be held up by something outside this
         * application. Its timeout is short because the request itself times out in
         * five seconds and anything longer means the process is stuck rather than
         * waiting.
         */
        'supervisor-revalidation' => [
            'connection' => 'redis',
            'queue' => ['revalidation'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 30,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-platform' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            'supervisor-revalidation' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-platform' => [
                'maxProcesses' => 3,
            ],

            'supervisor-revalidation' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Address
    |--------------------------------------------------------------------------
    |
    | Where Horizon emails long wait and failure notices. Read by
    | App\Providers\HorizonServiceProvider, which leaves Horizon quiet when it is
    | unset rather than failing to send.
    |
    | Email, because invariant 10 says notifications are email only, and an operations
    | notice is still a notification.
    |
    */

    'notification_email' => env('HORIZON_NOTIFICATION_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
