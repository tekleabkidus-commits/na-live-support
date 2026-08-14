<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Exception;

/**
 * Internal control-flow sentinel for IdentityResolution's upsert path: the ON
 * CONFLICT arbiter said a concurrent first contact won, and the savepoint
 * must roll the orphan shell back — DB::transaction rolls back only on a
 * throw. Never escapes the resolver.
 */
final class RaceLost extends Exception {}
