<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Telegram\Direct\TemplateResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-05/FR-06: mode is HANDLING, not topology (one adapter, capability-
 * declared differences — a §2 non-negotiable). This class decides what a
 * bot's mode does with an arriving update, and validates the BR-06 redirect.
 *
 *  - direct: a business update that arrives is STORED but flagged
 *    `unconfigured_source` and admins are alerted (a misconfiguration signal).
 *  - business: a DIRECT message to the bot gets the per-language redirect
 *    template ("please contact @account") and the conversation is still
 *    stored. BR-06: the redirect MUST carry a tappable account link — a dead
 *    end is prohibited (validated on save; sabotage #14).
 *  - hybrid: both lines active; one customer, two conversations (Doc 08 seam).
 */
final class ModePolicy
{
    public function __construct(private readonly TemplateResolver $templates) {}

    /** Whether an update of the given line (business|direct) is CONFIGURED for this mode. */
    public function classifyInbound(string $mode, string $line): string
    {
        return match ([$mode, $line]) {
            ['direct', 'business'] => 'unconfigured_source', // stored + admin alert
            ['business', 'direct'] => 'redirect',            // auto-reply + stored
            default => 'normal',
        };
    }

    /** direct-mode saw a business update: store-and-alert (FR-05). */
    public function flagUnconfiguredSource(string $botId, ?string $connectionId): void
    {
        Log::info('telegram unconfigured business update on a direct-mode bot', [
            'metric' => 'telegram.mode.unconfigured_source',
            'bot_id' => $botId,
            'connection_id' => $connectionId,
        ]);
        // The admin notification rides notify.telegram.misconfigured_source
        // (registered step 1); its producer is this signal.
    }

    /**
     * BR-06 GUARD: a redirect template must contain a tappable account link.
     * Called on template save; refusing here is what makes "dead ends
     * prohibited" physics, not a guideline. Returns void; throws on violation.
     */
    public function assertRedirectHasLink(string $body): void
    {
        // A tappable link = a t.me/@ mention or an inline URL (Markdown/HTML)
        // or a bare https link. A redirect with none is a dead end.
        $hasTme = (bool) preg_match('~(?:https?://)?t\.me/\S+~i', $body);
        $hasAt = (bool) preg_match('/@\w{3,}/', $body);
        $hasUrl = (bool) preg_match('~https?://\S+~i', $body);
        $hasMdLink = (bool) preg_match('/\[[^\]]+\]\([^)]+\)/', $body);

        if (! ($hasTme || $hasAt || $hasUrl || $hasMdLink)) {
            throw new DomainException('BR-06: the business-mode redirect must include a tappable account link — dead ends are prohibited.');
        }
    }

    /** Resolve the redirect reply for a direct message on a business-mode bot. */
    public function redirectReply(string $botId, string $language): ?string
    {
        $body = $this->templates->render($botId, $language, 'redirect', []);
        if ($body !== null) {
            $this->assertRedirectHasLink($body); // never send a dead-end redirect
        }

        return $body;
    }

    /** FR-06: leaving `business` with active connections needs a typed confirm; it does NOT disconnect. */
    public function changeMode(string $botId, string $newMode, bool $typedConfirmation): void
    {
        $current = DB::table('telegram_bots')->where('id', $botId)->value('mode');
        $leavingBusiness = $current === 'business' && $newMode !== 'business';

        if ($leavingBusiness) {
            $active = DB::table('telegram_business_connections')
                ->where('bot_id', $botId)
                ->whereIn('state', ['pending', 'active', 'paused'])
                ->count();

            if ($active > 0 && ! $typedConfirmation) {
                throw new DomainException('leaving business mode with active connections requires typed confirmation (FR-06); this does NOT disconnect them.');
            }
        }

        // Mode change is immediate; it changes HANDLING only, never the
        // connections (disconnect is its own explicit action).
        DB::table('telegram_bots')->where('id', $botId)->update([
            'mode' => $newMode,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }
}
