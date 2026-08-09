<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Media\ClamdClient;
use App\Domain\Conversations\Media\MediaFetcher;
use App\Domain\Conversations\Media\MediaPipeline;
use App\Domain\Conversations\Media\MediaStore;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 04's container wiring. The AdapterRegistry is a SINGLETON: one directory of
 * channels per process (FR-09). Real registrations arrive with Doc 05 (telegram) and
 * Doc 13 (webchat) booting their adapters here; this session the registry starts
 * empty and tests register fake adapters (§21's fake-adapter-first order).
 *
 * The media-chain services (ADR 0008) carry their infra endpoints from
 * config/media.php — constructor scalars rather than config() calls inside the
 * classes, so a test can build one against a dead port (EC-11's fixture) without
 * touching global config.
 */
final class ConversationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);

        $this->app->bind(ClamdClient::class, function (Application $app): ClamdClient {
            /** @var array{host: string, port: int, timeout_seconds: int} $clamd */
            $clamd = $app->make('config')->get('media.clamd');

            return new ClamdClient($clamd['host'], $clamd['port'], $clamd['timeout_seconds']);
        });

        $this->app->bind(MediaFetcher::class, function (Application $app): MediaFetcher {
            $proxy = $app->make('config')->get('media.egress_proxy');

            return new MediaFetcher(is_string($proxy) ? $proxy : '');
        });

        $this->app->singleton(\App\Domain\Conversations\Media\SourceUrlResolvers::class);

        $this->app->bind(MediaPipeline::class, function (Application $app): MediaPipeline {
            return new MediaPipeline(
                $app->make(MediaFetcher::class),
                $app->make(\App\Domain\Conversations\Media\SourceUrlResolvers::class),
                $app->make(ClamdClient::class),
                $app->make(MediaStore::class),
                $app->make(MessageRepository::class),
                $app->make(EventPublisher::class),
                $app->make(ComposeOutbound::class),
                $app->make(SettingsRepository::class),
                $app->make('config')->integer('media.scan_hold_minutes'),
            );
        });

        $this->app->bind(MediaStore::class, function (Application $app): MediaStore {
            $config = $app->make('config');

            return new MediaStore(
                $app->make(FilesystemFactory::class),
                $app->make(TenantContext::class),
                $config->string('media.disk'),
                $config->boolean('media.sse'),
                $config->integer('media.signed_url_ttl_seconds'),
            );
        });
    }
}
