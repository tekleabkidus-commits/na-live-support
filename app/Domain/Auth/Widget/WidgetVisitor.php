<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The widget guard's principal (Doc 02 FR-21, BR-06, T-02).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS CLASS NOT BEING App\Models\User IS THE SECURITY CONTROL.
 *
 * BR-06: "Visitor tokens grant zero access to staff surfaces STRUCTURALLY
 * (separate guard, route group, cookie scope) regardless of any scope
 * misconfiguration." The staff and api guards authenticate User models through
 * user providers; this object is not a User, is not an Eloquent model, has no
 * database row, no roles, no permissions, and no id in the users table. There
 * is no configuration state — no scope grant, no provider typo, no future
 * refactor of permission checks — under which it can satisfy `auth:staff` or
 * `auth:api`, because those guards cannot even retrieve it. The partition test
 * asserts this from both directions.
 *
 * It implements Authenticatable only so Laravel's auth plumbing (Request::user,
 * channel authorization callbacks) can carry it. The password/remember-token
 * members are the interface's ceremony; a visitor HAS no password, and each
 * throws rather than returning an empty string so any code path that tries to
 * treat a visitor as a credentialed account fails loudly at the exact line
 * that made the category error.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class WidgetVisitor implements Authenticatable
{
    public function __construct(
        public string $visitorId,
        public string $tenantId,
        public string $widgetId,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'visitor_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->visitorId;
    }

    public function getAuthPasswordName(): string
    {
        throw new \LogicException('A widget visitor has no password (Doc 02 BR-06; N-06 — visitors are anonymous).');
    }

    public function getAuthPassword(): string
    {
        throw new \LogicException('A widget visitor has no password (Doc 02 BR-06; N-06 — visitors are anonymous).');
    }

    public function getRememberToken(): string
    {
        throw new \LogicException('A widget visitor has no remember token; continuity is the FR-21 visitor token.');
    }

    public function setRememberToken($value): void
    {
        throw new \LogicException('A widget visitor has no remember token; continuity is the FR-21 visitor token.');
    }

    public function getRememberTokenName(): string
    {
        throw new \LogicException('A widget visitor has no remember token; continuity is the FR-21 visitor token.');
    }
}
