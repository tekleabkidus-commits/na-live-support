<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Webchat\Realtime\WebchatPusher;
use App\Domain\Webchat\WebchatAdapter;
use App\Support\Cache\TenantCache;
use App\Support\Flags\FeatureRegistry;
use App\Support\Tenancy\TenantContext;
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
 * platform-scoped, default off) is on for the BOUND tenant — resolved at
 * capability time, never at boot.
 */
final class WebchatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // FR-16: the intake's opaque source scheme — Doc 04's resolver door (N-20: keyed on the scheme, never a channel name).
        $this->app->make(\App\Domain\Conversations\Media\SourceUrlResolvers::class)
            ->register(\App\Domain\Webchat\Media\WidgetIntake::SCHEME, fn (string $reference): string => $this->app->make(\App\Domain\Webchat\Media\WidgetIntake::class)->resolve($reference));

        $pusher = fn (): WebchatPusher => $this->app->make(WebchatPusher::class);
        $cache = fn (): TenantCache => $this->app->make(TenantCache::class);

        // T-02 verbatim; the voice kind joins ONLY while the platform flag
        // `widget.voice_recording` is on for the bound tenant (resolved at
        // capability time — a boot-time read would have no tenant to ask).
        $capabilities = function (): Capabilities {
            $voice = false;
            if ($this->app->make(TenantContext::class)->tenantIdOrNull() !== null) {
                $voice = $this->app->make(FeatureRegistry::class)->active('widget.voice_recording');
            }

            return new Capabilities(
                maxText: 4000,
                mediaTypes: $voice ? ['photo', 'video', 'document', 'voice'] : ['photo', 'video', 'document'],
                supportsTyping: true,
                supportsEdit: false,
                supportsDelete: false,
                supportsButtons: true,
                replyWindow: ReplyWindow::none(),
            );
        };

        $this->app->make(AdapterRegistry::class)->register(
            WebchatAdapter::CHANNEL,
            new WebchatAdapter(
                capabilities: $capabilities,
                push: static function (string $visitorId, string $suffix, string $event, array $payload) use ($pusher, $cache): void {
                    $pusher()->toVisitor($visitorId, $suffix, $event, $payload);
                    // T-04 polling parity ("feature-identical minus typing granularity"):
                    // a staff typing frame also leaves a 6 s mark the cursor endpoint's
                    // envelope reads, so a visitor on polling sees typing at 5 s grain.
                    if ($event === WebchatAdapter::EVENT_TYPING) {
                        $cache()->put('webchat-staff-typing:'.$visitorId, '1', 6, 'TTL expiry (the window IS the invalidation)');
                    }
                },
                health: static fn (): AdapterHealth => AdapterHealth::ok(),
            ),
            'webchat-in',
            'webchat-out',
        );
    }
}
