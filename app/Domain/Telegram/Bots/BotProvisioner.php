<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Bots;

use App\Domain\Telegram\Api\TelegramApi;
use App\Models\TelegramBot;
use App\Models\TenantKey;
use App\Models\WebhookCredential;
use App\Support\Tenancy\TenantKeyIssuer;
use DomainException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Bot registration & webhook lifecycle (Doc 05 FR-01/FR-03/FR-04, SEC-02).
 *
 * THE WRITE PATH the S15 standing note promised: registration mints the
 * tenant_keys row (the unguessable URL segment) and the webhook_credentials
 * row (the secret_token's hash) that the probe era only seeded by hand.
 *
 * FR-01's allowed_updates list is a CONSTANT asserted verbatim by test — a
 * missing update type is a silent feature amputation (the session prompt's
 * own words).
 *
 * ROTATION SEMANTICS (FR-03/SEC-02, strengthened deliberately): every
 * (re-)registration mints a FRESH key AND a fresh 256-bit secret_token —
 * the key plaintext is shown to Telegram once (inside the webhook URL) and
 * never stored (SEC-03's shown-once posture makes same-URL re-registration
 * impossible BY DESIGN, so rotation rotates both credentials). The old key
 * is revoked in the same transaction; Telegram redelivers pending updates
 * to the new URL, so nothing drops (FR-03's own text). Idempotent and safe
 * anytime.
 *
 * FR-04: archive, never delete — zero active connections OR an explicit
 * cascade acknowledgment; deleteWebhook; conversations stay readable
 * (row physics: DELETE is revoked on telegram_bots).
 */
final class BotProvisioner
{
    /** FR-01's exact list — asserted VERBATIM in BotProvisioningTest. */
    public const ALLOWED_UPDATES = [
        'message',
        'edited_message',
        'callback_query',
        'my_chat_member',
        'business_connection',
        'business_message',
        'edited_business_message',
        'deleted_business_messages',
    ];

    public function __construct(
        private readonly TelegramApi $api,
        private readonly TenantKeyIssuer $issuer,
    ) {}

    /**
     * FR-01 wizard step 1: validate the token against getMe, mint the route
     * key + webhook secret, register the webhook, verify via getWebhookInfo.
     *
     * @return array{bot: TelegramBot, webhook_url: string, verified: bool}
     */
    public function register(#[SensitiveParameter] string $botToken, string $mode): array
    {
        $me = $this->api->call($botToken, 'getMe');

        $telegramBotId = is_int($me['id'] ?? null) ? $me['id'] : null;
        $username = is_string($me['username'] ?? null) ? $me['username'] : null;

        if ($telegramBotId === null || $username === null) {
            throw new DomainException('getMe answered without id/username — not a usable bot token.');
        }

        $bot = DB::transaction(function () use ($botToken, $mode, $telegramBotId, $username, $me): TelegramBot {
            $bot = TelegramBot::query()->create([
                'telegram_bot_id' => $telegramBotId,
                'username' => $username,
                'display_name' => is_string($me['first_name'] ?? null) ? $me['first_name'] : $username,
                'bot_token' => $botToken,
                'mode' => $mode,
            ]);

            return $bot;
        });

        [$webhookUrl, $verified] = $this->registerWebhook($bot);

        return ['bot' => $bot->refresh(), 'webhook_url' => $webhookUrl, 'verified' => $verified];
    }

    /**
     * FR-03: idempotent, safe anytime; rotates BOTH credentials (see the
     * class docblock). Returns the verification outcome.
     *
     * @return array{webhook_url: string, verified: bool}
     */
    public function reRegister(TelegramBot $bot): array
    {
        [$webhookUrl, $verified] = $this->registerWebhook($bot);

        return ['webhook_url' => $webhookUrl, 'verified' => $verified];
    }

    /**
     * FR-04: archive the bot. Refuses while active business connections
     * exist unless the caller explicitly acknowledged the cascade.
     */
    public function archive(TelegramBot $bot, bool $cascadeAcknowledged): void
    {
        $activeConnections = DB::table('telegram_business_connections')
            ->where('bot_id', $bot->id)
            ->whereIn('state', ['pending', 'active', 'paused'])
            ->count();

        if ($activeConnections > 0 && ! $cascadeAcknowledged) {
            throw new DomainException(sprintf(
                'bot has %d non-revoked business connection(s); archiving requires the typed cascade acknowledgment (FR-04).',
                $activeConnections,
            ));
        }

        $token = $bot->bot_token;
        if (is_string($token)) {
            $this->api->call($token, 'deleteWebhook');
        }

        DB::transaction(function () use ($bot): void {
            if (is_string($bot->key_id)) {
                TenantKey::query()->whereKey($bot->key_id)
                    ->update(['revoked_at' => DB::raw('clock_timestamp()')]);
            }

            $bot->forceFill(['status' => 'archived'])->save();
        });
    }

    /**
     * The shared mint-and-register core: fresh key, fresh secret, setWebhook
     * with FR-01's exact allowed_updates, getWebhookInfo verification.
     *
     * @return array{0: string, 1: bool} [webhook_url, verified]
     */
    private function registerWebhook(TelegramBot $bot): array
    {
        $token = $bot->bot_token;
        if (! is_string($token)) {
            throw new DomainException('bot has no stored token; re-entry required (SEC-01).');
        }

        $secretToken = bin2hex(random_bytes(32)); // 256-bit (SEC-02)

        [$keyId, $plaintextKey] = DB::transaction(function () use ($bot, $secretToken): array {
            $issued = $this->issuer->issue('telegram_bot', 'telegram bot @'.$bot->username);
            $keyId = $issued['key']->getKey();
            if (! is_string($keyId)) {
                throw new DomainException('tenant key issued without a string id.');
            }

            WebhookCredential::query()->create([
                'key_id' => $keyId,
                'header_secret_hash' => hash('sha256', $secretToken),
            ]);

            $previousKeyId = $bot->key_id;
            $bot->forceFill(['key_id' => $keyId])->save();

            if (is_string($previousKeyId)) {
                TenantKey::query()->whereKey($previousKeyId)
                    ->update(['revoked_at' => DB::raw('clock_timestamp()')]);
            }

            return [$keyId, $issued['plaintext']];
        });

        $webhookBase = config('telegram.webhook_base');
        $appUrl = config('app.url');
        $base = is_string($webhookBase) && $webhookBase !== ''
            ? $webhookBase
            : (is_string($appUrl) ? $appUrl : '');
        $webhookUrl = rtrim($base, '/').'/api/webhooks/telegram/'.$plaintextKey;

        $this->api->call($token, 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
            'allowed_updates' => self::ALLOWED_UPDATES,
        ]);

        $this->registerCommands($token);

        $info = $this->api->call($token, 'getWebhookInfo');
        $verified = ($info['url'] ?? null) === $webhookUrl;

        return [$webhookUrl, $verified];
    }

    /**
     * FR-25's registration half (S23 step 12 — the self-audit found the
     * behaviours proven but this call missing): the five fixed v1 commands
     * registered via setMyCommands PER LANGUAGE. Descriptions come from the
     * SAME i18n keys the B6-S05 E10 table renders — one source, no drift.
     * The unscoped call is the C-08 fallback (English) for every other
     * client language. Idempotent; rides both register and re-register.
     */
    private function registerCommands(#[SensitiveParameter] string $token): void
    {
        $commands = ['start', 'menu', 'status', 'human', 'language'];
        $descriptions = [];
        foreach (['en', 'am'] as $language) {
            foreach ($commands as $command) {
                $description = __('telegram.ui.templates.cmd_'.$command, [], $language);
                $descriptions[$language][$command] = is_string($description) ? $description : $command;
            }
        }

        /**
         * @param  array<string, string>  $set
         * @return list<array{command: string, description: string}>
         */
        $commandSet = static function (array $set): array {
            $out = [];
            foreach ($set as $command => $description) {
                $out[] = ['command' => $command, 'description' => $description];
            }

            return $out;
        };

        // C-08 fallback first (no language_code), then each launch language.
        $this->api->call($token, 'setMyCommands', [
            'commands' => $commandSet($descriptions['en']),
        ]);
        foreach ($descriptions as $language => $set) {
            $this->api->call($token, 'setMyCommands', [
                'commands' => $commandSet($set),
                'language_code' => $language,
            ]);
        }
    }
}
