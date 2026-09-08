<?php

declare(strict_types=1);

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

    // Horizon metadata and every queue live on the DURABLE Redis instance
    // (AOF, never evicted) — see config/database.php for why.
    'use' => 'durable',

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
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
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
        'redis:telegram-in' => 60,
        'redis:telegram-out' => 60,
        'redis:webchat-in' => 60,
        'redis:webchat-out' => 60,
        'redis:media' => 60,
        'redis:ai' => 60,
        // Doc 14 HORIZON FAMILY AMENDMENT 1 (S14 step 2b): four SMS supervisors, one queue each, every queue sms- prefixed.
        'redis:sms-transactional' => 60,
        'redis:sms-reengagement' => 120,
        'redis:sms-broadcast' => 300,
        'redis:sms-dlr' => 30,
        'redis:automation' => 60,
        'redis:reports' => 300,
        'redis:outbox-relay' => 60,
        'redis:audit' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

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
        // App\Jobs\ExampleJob::class,
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

    /*
    |--------------------------------------------------------------------------
    | Queue families (CLAUDE.md §3, Doc 18 FR-16)
    |--------------------------------------------------------------------------
    |
    | ONE SUPERVISOR PER FAMILY, never a shared pool. Each family gets its own
    | worker processes, so saturation in one family (a Telegram burst, a slow
    | AI provider) can never starve another (the outbox relay, audit writes).
    | Families are empty today (Session 0) — the supervisors exist so that the
    | isolation property is in place before the first job is ever dispatched,
    | and so the smoke test can assert all eleven are supervised.
    |
    */

    'defaults' => [
        'telegram-in' => [
            'connection' => 'redis',
            'queue' => ['telegram-in'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'telegram-out' => [
            'connection' => 'redis',
            'queue' => ['telegram-out'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'webchat-in' => [
            'connection' => 'redis',
            'queue' => ['webchat-in'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'webchat-out' => [
            'connection' => 'redis',
            'queue' => ['webchat-out'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'media' => [
            'connection' => 'redis',
            'queue' => ['media'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],
        'ai' => [
            'connection' => 'redis',
            // ADR 0017 (amended at S09 step 3): the customer-blocking lane.
            // Doc 04 FS-03's supervisor physics pins ONE family per
            // supervisor (FailureScenariosTest caught the two-queue draft),
            // so background AI work rides its OWN supervisor below —
            // stronger than lane priority: a digest flood cannot eat
            // chatbot workers at all (FR-03).
            'queue' => ['ai'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],
        'ai-background' => [
            'connection' => 'redis',
            // ADR 0017: digests, QA pre-scores, anomaly scans, nightly
            // embeddings — work that must never delay a customer turn.
            'queue' => ['ai-background'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
        // Doc 14 HORIZON FAMILY AMENDMENT 1 (S14 step 2b, countersigned a8bf165): T-04's three
        // lanes + the DLR lane as FOUR supervisors, one queue each (Doc 04 FS-03's physics —
        // FailureScenariosTest pins one family per supervisor). Priority is CAPACITY, not
        // ordering (the ADR 0017 shape): broadcast load can never consume a transactional
        // worker. Every queue is sms- prefixed (the S0 bare `sms` queue is renamed here).
        'sms' => [
            'connection' => 'redis',
            'queue' => ['sms-transactional'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'sms-reengagement' => [
            'connection' => 'redis',
            'queue' => ['sms-reengagement'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 5,
        ],
        'sms-broadcast' => [
            'connection' => 'redis',
            'queue' => ['sms-broadcast'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
        'sms-dlr' => [
            'connection' => 'redis',
            'queue' => ['sms-dlr'],
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
        'automation' => [
            'connection' => 'redis',
            'queue' => ['automation'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'reports' => [
            'connection' => 'redis',
            'queue' => ['reports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
        'outbox-relay' => [
            'connection' => 'redis',
            'queue' => ['outbox-relay'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'audit' => [
            'connection' => 'redis',
            'queue' => ['audit'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        // ADR 0007 (Doc 02 FS-02): auth email is the account-recovery path and
        // must never queue behind bulk work; its depth is only a meaningful
        // FS-02 signal if the queue carries nothing but mail. `tries` stays 1
        // here because FS-02's retry ×6 backoff belongs to the Mailable itself
        // — a supervisor-level default would silently retry every future mail
        // job whether or not its document asked for that.
        'mail' => [
            'connection' => 'redis',
            'queue' => ['mail'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'telegram-in' => ['maxProcesses' => 4],
            'telegram-out' => ['maxProcesses' => 4],
            'webchat-in' => ['maxProcesses' => 2],
            'webchat-out' => ['maxProcesses' => 2],
            'media' => ['maxProcesses' => 2],
            'ai' => ['maxProcesses' => 2],
            'sms' => ['maxProcesses' => 3],
            'sms-reengagement' => ['maxProcesses' => 2],
            'sms-broadcast' => ['maxProcesses' => 1],
            'sms-dlr' => ['maxProcesses' => 2],
            'automation' => ['maxProcesses' => 2],
            'reports' => ['maxProcesses' => 1],
            'outbox-relay' => ['maxProcesses' => 2],
            'audit' => ['maxProcesses' => 2],
        ],

        // Local runs every family with a single process each — the families
        // themselves must exist locally (parity, Doc 01 SEC-01).
        'local' => [
            'telegram-in' => ['maxProcesses' => 1],
            'telegram-out' => ['maxProcesses' => 1],
            'webchat-in' => ['maxProcesses' => 1],
            'webchat-out' => ['maxProcesses' => 1],
            'media' => ['maxProcesses' => 1],
            'ai' => ['maxProcesses' => 1],
            'sms' => ['maxProcesses' => 1],
            'sms-reengagement' => ['maxProcesses' => 1],
            'sms-broadcast' => ['maxProcesses' => 1],
            'sms-dlr' => ['maxProcesses' => 1],
            'automation' => ['maxProcesses' => 1],
            'reports' => ['maxProcesses' => 1],
            'outbox-relay' => ['maxProcesses' => 1],
            'audit' => ['maxProcesses' => 1],
        ],
    ],

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
