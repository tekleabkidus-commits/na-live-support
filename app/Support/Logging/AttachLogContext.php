<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\Tenancy\TenantContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Every log record carries tenant_id + trace_id (Doc 02 SEC-08; C-14).
 *
 * The ids come from TenantContext at RECORD time, resolved from the container
 * on every record rather than captured at construction: the context is a
 * scoped binding that Octane flushes per request, while Monolog processors
 * live as long as the resolved channel — a captured reference would carry
 * request A's ids into request B's log lines, the exact R-01 failure mode
 * TenantContext's own docblock warns about.
 *
 * `trace_id` is TenantContext::correlationId(), which self-generates when
 * nothing upstream supplied one — so a log line written before tenant
 * resolution (or from a platform worker with no tenant at all) is still
 * traceable, per C-14's "available even with no tenant bound".
 *
 * FAIL-OPEN ON THE CONTEXT LOOKUP, deliberately: a processor that throws
 * kills the whole logging pipeline, and "no logs at all" is strictly worse
 * than "this one line lacks ids". The seeded suite asserts the ids ARE
 * present on real request paths, so a regression here goes red there rather
 * than silently.
 */
final class AttachLogContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $context = app(TenantContext::class);

            $record->extra['tenant_id'] = $context->tenantIdOrNull();
            $record->extra['trace_id'] = $context->correlationId();
        } catch (\Throwable) {
            // Container not ready (pre-boot log lines): ship the record bare.
        }

        return $record;
    }
}
