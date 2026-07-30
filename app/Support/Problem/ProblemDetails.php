<?php

declare(strict_types=1);

namespace App\Support\Problem;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 problem-details responses (Doc 01 T-07, C-04).
 *
 * The shape is fixed by C-04: `{type, title, status, code, detail, errors{}, trace_id}`.
 *
 * `trace_id` MATCHES THE LOGS, which is the clause that makes this useful rather
 * than ceremonial. An operator reports "I got an error, the id was 019fb2…"; that id
 * is the correlation id carried by the request, every job it spawned and every event
 * it published (C-14), so one search reconstructs the whole causal chain. A trace id
 * that appeared only in the response would be decoration.
 *
 * The Content-Type is `application/problem+json`, per the RFC. Clients that sniff
 * content types need to see it, and it distinguishes an error body from a successful
 * one that happens to have a `status` field.
 */
final class ProblemDetails
{
    /**
     * @param  array<string, list<string>>  $errors  per-field validation detail
     * @param  array<string, mixed>  $extra  additional members (the RFC permits them)
     */
    public static function response(
        ErrorCode $code,
        int $status,
        string $detail,
        array $errors = [],
        array $extra = [],
    ): JsonResponse {
        $body = [
            // `type` is a URI identifying the problem KIND. Kept as a relative
            // documentation path rather than a live URL: RFC 9457 does not require it
            // to resolve, and promising a URL the platform does not serve is worse
            // than not promising one.
            'type' => sprintf('/problems/%s', $code->value),
            'title' => __($code->titleI18nKey()),
            'status' => $status,
            'code' => $code->value,
            'detail' => $detail,
            'errors' => $errors,
            'trace_id' => app(TenantContext::class)->correlationId(),
        ];

        return new JsonResponse(
            $body + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
