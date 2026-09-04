<?php

declare(strict_types=1);

namespace App\Support\Secrets;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * C-13's INTERIM (SCHEMA AMENDMENT 7 RIDER 1): secret material behind a
 * `vault://` reference. The DB row is the ciphertext under APP_KEY — the A-19
 * Telegram-token shape generalized — and the reference is all any other row
 * ever holds. Plaintext exists in memory for one comparison and is never
 * logged, serialized, returned to a client twice (shown ONCE at mint) or
 * written anywhere else. Doc 18 replaces the table with the real vault behind
 * this same contract.
 */
final class VaultInterim
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly TenantContext $context,
    ) {}

    /** Store plaintext under a fresh reference; returns the reference. */
    public function put(string $ref, string $plaintext): string
    {
        if (! str_starts_with($ref, 'vault://')) {
            throw new RuntimeException('a vault reference starts with vault:// (C-13).');
        }

        DB::table('vault_secrets')->insert([
            'ref' => $ref,
            'tenant_id' => $this->context->tenantId(),
            'ciphertext' => $this->encrypter->encryptString($plaintext),
        ]);

        return $ref;
    }

    /** Resolve a reference to its plaintext for ONE use; null when absent or retired. */
    public function reveal(string $ref): ?string
    {
        /** @var object{ciphertext: string, retired_at: ?string}|null $row */
        $row = DB::table('vault_secrets')->where('ref', $ref)->first(['ciphertext', 'retired_at']);
        if ($row === null || $row->retired_at !== null) {
            return null;
        }

        return $this->encrypter->decryptString($row->ciphertext);
    }

    /** Close a predecessor's overlap window: the row stays, retired (never deleted). */
    public function retire(string $ref): void
    {
        DB::table('vault_secrets')->where('ref', $ref)->whereNull('retired_at')
            ->update(['retired_at' => DB::raw('clock_timestamp()')]);
    }
}
