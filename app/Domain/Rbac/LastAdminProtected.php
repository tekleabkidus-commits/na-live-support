<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\User;
use RuntimeException;

/**
 * FR-08a's hard block. Carries the other holders (empty = the blocked user
 * IS the last one) so the §8 error state can name exactly who else holds
 * the rights — or say plainly that nobody does.
 *
 * @property list<array{id: string, name: string}> $otherHolders
 */
final class LastAdminProtected extends RuntimeException
{
    /**
     * @param  list<array{id: string, name: string}>  $otherHolders
     */
    public function __construct(
        public readonly ?User $user,
        public readonly array $otherHolders,
    ) {
        parent::__construct('The last administrator cannot lose admin.roles.manage + admin.users.manage (Doc 03 FR-08a).');
    }
}
