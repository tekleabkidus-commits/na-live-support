<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * One catalog entry, typed (Doc 03 FR-01): the key plus the attributes every
 * consumer branches on — module for grouping and scope maps, risk for the
 * high-risk rails (FR-08b, SEC-03), scope dimension for the resolver's scope
 * check (FR-05) and the matrix UI's scope bar (§8).
 */
final readonly class PermissionDefinition
{
    public function __construct(
        public string $key,
        public string $module,
        public string $labelI18nKey,
        public string $descriptionI18nKey,
        public bool $high,
        public bool $scoped,
    ) {}
}
