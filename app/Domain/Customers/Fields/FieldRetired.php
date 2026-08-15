<?php

declare(strict_types=1);

namespace App\Domain\Customers\Fields;

use DomainException;

/** EC-09: a retired definition is read-only — the write refuses by type. */
final class FieldRetired extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("field '{$key}' is retired — the definition went, the fact stays read-only (EC-09)");
    }
}
