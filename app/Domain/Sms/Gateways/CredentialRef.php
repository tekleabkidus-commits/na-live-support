<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use InvalidArgumentException;
use Stringable;

/**
 * Doc 14 FR-07 / SEC-01 / C-13 — a gateway credential is a `vault://sms/…`
 * REFERENCE into ADR 0021's `vault_secrets` interim, never a value. This
 * value object is the model-level refusal (STOP-R 5, sequenced before the
 * gateway table's first write); the `sms_gateways.credential_ref` CHECK is
 * the physics half (SCHEMA AMENDMENT 8, 23514 control). There is no accessor
 * that reveals anything: revealing is `VaultInterim::reveal`, in memory, at
 * the driver's call, once.
 */
final class CredentialRef implements Stringable
{
    private const NAMESPACE = 'sms';

    private const PATTERN = '~\Avault://sms/[A-Za-z0-9_\-./]+\z~';

    private function __construct(private readonly string $ref) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('a gateway credential is a vault://sms/… reference, never a value (Doc 14 SEC-01, C-13)');
        }

        return new self($value);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }

    public function __toString(): string
    {
        return $this->ref;
    }
}
