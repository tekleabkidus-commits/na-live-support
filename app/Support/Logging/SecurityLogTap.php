<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;

/**
 * The one tap every log channel must carry (Doc 02 SEC-08; C-14).
 *
 * Declared in config/logging.php on EVERY channel except `null` (discards
 * all records) and `emergency` (Laravel's fixed-config fallback, which only
 * fires when logging itself is broken and supports no tap) — the owner-
 * approved exemption list, made safe by the channel-pin test in
 * tests/Security/LogScrubberTest.php: a future channel added without this
 * tap goes red there, it does not silently ship unscrubbed.
 *
 * PUSH ORDER IS LOAD-BEARING. Monolog runs processors front-to-back and
 * pushProcessor() PREPENDS, so ScrubSecrets is pushed first to run LAST —
 * after AttachLogContext, so nothing any earlier processor attaches can
 * bypass the scrub.
 *
 * Stack channels inherit their children's processors (LogManager's
 * createStackDriver flat-maps them), so a record through `stack` may pass
 * this pair more than once. Both processors are idempotent by construction:
 * re-scrubbing "[scrubbed]" is a no-op and AttachLogContext overwrites its
 * own two keys.
 */
final class SecurityLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        $monolog->pushProcessor(new ScrubSecrets);
        $monolog->pushProcessor(new AttachLogContext);
    }
}
