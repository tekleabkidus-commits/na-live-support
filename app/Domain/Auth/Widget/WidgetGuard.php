<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

use App\Http\Middleware\ResolveTenant;
use App\Models\Widget;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The `widget` guard (Doc 02 FR-21, FR-22, T-02, BR-06).
 *
 * Authenticates the post-session widget surface: the visitor token from the
 * X-Visitor-Token header, PAIRED with the rotating secondary cookie (FR-22).
 * Tenant identity is not this guard's job — the widget key resolved it one
 * middleware earlier (T-02's order: tenant-resolve → guard → tenant-state),
 * so by the time this runs, the store read is an ordinary tenant-prefixed
 * lookup.
 *
 * WHAT A MISMATCH DOES HERE: refuses, quietly, with a security metric. The
 * FR-22 "silent re-issue" lives on POST /session only (step-10 ruling,
 * decision 3) — an API request with a token that lacks its cookie gets a
 * plain 401, the widget client re-POSTs /session, and the visitor never sees
 * an error. Re-issuing from arbitrary endpoints would turn every route into
 * a session factory.
 *
 * FOUR checks, in credential-cheapness order, all quiet (C-10's posture —
 * refusals never explain which layer refused):
 *
 *   1. a token header is present
 *   2. the token exists in this tenant's store (also slides the TTL — FR-21)
 *   3. the record belongs to the SAME WIDGET whose key resolved this request
 *      — a token minted under widget A is not a credential for widget B, even
 *      within one tenant (FR-21's {tenant, widget, visitor_id} binding)
 *   4. the paired cookie matches, current-or-previous (FR-22)
 */
final class WidgetGuard implements Guard
{
    public const TOKEN_HEADER = 'X-Visitor-Token';

    /**
     * The FR-22 secondary cookie. Scoped to the widget path prefix — BR-06's
     * "separate cookie scope": it never rides along to staff routes, and staff
     * cookies never ride here (the widget route group carries no cookie
     * middleware, so an encrypted staff session cookie is noise to it).
     */
    public const COOKIE_NAME = 'nlw_visitor';

    public const COOKIE_PATH = '/api/widget';

    private ?WidgetVisitor $visitor = null;

    private bool $attempted = false;

    public function __construct(
        private readonly VisitorTokenStore $store,
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    public function user(): ?Authenticatable
    {
        if ($this->attempted) {
            return $this->visitor;
        }

        $this->attempted = true;

        if (! $this->context->isBound()) {
            return null;
        }

        $tenantId = $this->context->tenantId();
        $token = $this->request->header(self::TOKEN_HEADER);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $record = $this->store->find($tenantId, $token);

        if ($record === null) {
            $this->refuse('unknown_token');

            return null;
        }

        $widget = $this->resolvedWidget();

        if ($widget === null || $widget->getKey() !== $record->widgetId) {
            $this->refuse('widget_mismatch');

            return null;
        }

        $cookie = $this->request->cookies->get(self::COOKIE_NAME);

        if (! is_string($cookie) || $cookie === '' || ! $record->cookieMatches($cookie)) {
            $this->refuse('cookie_mismatch');

            return null;
        }

        $this->store->touch($tenantId, $token, $record, CarbonImmutable::now());

        $this->visitor = new WidgetVisitor(
            visitorId: $record->visitorId,
            tenantId: $tenantId,
            widgetId: $record->widgetId,
        );

        return $this->visitor;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): ?string
    {
        // Via the property, not user(): Larastan types every Guard::user()
        // as the app's User model, which this guard exists to never carry.
        $this->user();

        return $this->visitor?->visitorId;
    }

    public function hasUser(): bool
    {
        return $this->visitor !== null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        // No credential-array path exists on purpose: the ONLY way to become a
        // visitor is the header+cookie pair on a live request. Fortify-style
        // programmatic validation is a staff-guard concept.
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        if (! $user instanceof WidgetVisitor) {
            // BR-06 from the inside: nothing — not even a test helper — may
            // plant a staff User behind the widget guard.
            throw new \LogicException(sprintf(
                'The widget guard carries only %s principals, got %s (Doc 02 BR-06).',
                WidgetVisitor::class,
                $user::class,
            ));
        }

        $this->visitor = $user;
        $this->attempted = true;

        return $this;
    }

    private function resolvedWidget(): ?Widget
    {
        $keyId = $this->request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        if (! is_string($keyId) || $keyId === '') {
            return null;
        }

        return Widget::query()->where('key_id', $keyId)->first();
    }

    private function refuse(string $internalReason): void
    {
        // C-10's shape: quiet to the caller, loud to whoever watches metrics.
        // A stream of these is either a broken integration or a probe; the
        // reason distinguishes token theft (cookie_mismatch) from noise.
        Log::warning('widget visitor authentication refused', [
            'reason' => $internalReason,
            'security_metric' => 'widget.visitor_auth_refused',
        ]);
    }
}
