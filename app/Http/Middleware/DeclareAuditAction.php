<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declares which audit action a mutating route emits (CLAUDE.md §2; Doc 17 FR-11).
 *
 * Session 0 built the `audit-coverage` gate expecting exactly this: it reads each mutating
 * route's middleware looking for `audit:<action_key>`, and fails the build on any route
 * that declares nothing or declares an action the registry does not know. The middleware
 * itself was never written, because Session 0 had no mutating routes; Doc 01's settings
 * write path is the first.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS MORE THAN AN ANNOTATION.
 *
 * A declaration a static gate reads is already worth something: a route that mutates state
 * without naming its audit action cannot merge. But a declaration nobody checks at runtime
 * decays — the route keeps its `audit:` tag long after someone removes the audit call
 * inside it, and the gate stays green while the trail goes silent.
 *
 * So this also verifies, at runtime, that a successful mutating request actually WROTE the
 * action it declared. Doc 17 owns the full coverage harness (Doc 19 FR-14 calls it "the
 * universal side-effect oracle"); this is the narrow version Doc 01 can support today,
 * and it turns the tag from a promise into a checked one.
 *
 * It DOES NOT fail the request. A mutation that succeeded and forgot to audit is a defect,
 * but refusing the response would turn a bookkeeping bug into an outage, and the mutation
 * has already committed — the user would be told it failed when it did not. It throws in
 * local and testing (so the test suite catches it) and logs loudly elsewhere.
 * ────────────────────────────────────────────────────────────────────────────────
 */
final class DeclareAuditAction
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $actionKey  must exist in registries/audit-coverage.php
     */
    public function handle(Request $request, Closure $next, string $actionKey): Response
    {
        $before = AuditLogger::recordedCount($actionKey);

        $response = $next($request);

        // A-37 (advisor-ruled, 2026-08-13): a handler that took a DOCUMENTED
        // noop path may declare it — explicitly, in code, at the exact
        // branch, tied to the owning service's own noop return (EC-07's
        // same-state success is the first such path: "no error, no event,
        // no audit entry" is its S22-proven contract). The declaration is
        // the signed-skip shape: a silent missing audit still throws below;
        // only a deliberate, reviewable attribute passes.
        if ($request->attributes->get('audit.noop') === true) {
            return $response;
        }

        // Only successful mutations are expected to have audited. A 4xx means the mutation
        // did not happen, and auditing a rejected attempt is the exception (EC-06's
        // conflict entry) rather than the rule.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $after = AuditLogger::recordedCount($actionKey);

            if ($after === $before) {
                $message = sprintf(
                    'Route %s declares `audit:%s` but the request completed successfully without writing '
                    .'that audit entry. Either the handler stopped auditing or the declaration is wrong — '
                    .'both leave a state change with no trail (CLAUDE.md §2, Doc 17 FR-11).',
                    $request->route()?->getName() ?? $request->path(),
                    $actionKey,
                );

                if (app()->environment(['local', 'testing'])) {
                    throw new LogicException($message);
                }

                logger()->error($message, ['action_key' => $actionKey]);
            }
        }

        return $response;
    }
}
