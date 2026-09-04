<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Webchat\Realtime\WebchatPusher;
use App\Domain\Webchat\WebchatAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 13 T-02 / Doc 04 T-01 — the SECOND real channel registration: one
 * adapter for channel 'webchat' on the constitutional webchat-in/webchat-out
 * Horizon families (config/horizon.php, provisioned since S0). Registration
 * is DATA at the edge (AdapterRegistry); no domain code ever compares against
 * the literal channel name (N-20).
 *
 * T-02's envelope, verbatim. The voice kind joins the media list ONLY while
 * the platform flag `widget.voice_recording` (registries/features.php,
 * platform-scoped, default off — step 2) is on; until the flag is registered
 * the list is the three always-on kinds.
 */
final class WebchatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $pusher = fn (): WebchatPusher => $this->app->make(WebchatPusher::class);

        $this->app->make(AdapterRegistry::class)->register(
            WebchatAdapter::CHANNEL,
            new WebchatAdapter(
                capabilities: new Capabilities(
                    maxText: 4000,
                    mediaTypes: ['photo', 'video', 'document'],
                    supportsTyping: true,
                    supportsEdit: false,
                    supportsDelete: false,
                    supportsButtons: true,
                    replyWindow: ReplyWindow::none(),
                ),
                push: static function (string $visitorId, string $suffix, string $event, array $payload) use ($pusher): void {
                    $pusher()->toVisitor($visitorId, $suffix, $event, $payload);
                },
                health: static fn (): AdapterHealth => AdapterHealth::ok(),
            ),
            'webchat-in',
            'webchat-out',
        );
    }
}
