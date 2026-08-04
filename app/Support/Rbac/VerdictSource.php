<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * WHERE a verdict came from (Doc 03 FR-04/FR-05) — the precedence chain's
 * rungs, in order. The explorer's "why" (§8), the deny log's context (FR-11)
 * and AC-02's matrix all read this, so it is part of the resolver's contract,
 * not presentation.
 */
enum VerdictSource: string
{
    case OverrideDeny = 'override_deny';
    case OverrideAllow = 'override_allow';
    case RoleGrant = 'role';
    case ImplicitDeny = 'implicit_deny';
}
