<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 04's container wiring. The AdapterRegistry is a SINGLETON: one directory of
 * channels per process (FR-09). Real registrations arrive with Doc 05 (telegram) and
 * Doc 13 (webchat) booting their adapters here; this session the registry starts
 * empty and tests register fake adapters (§21's fake-adapter-first order).
 */
final class ConversationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
    }
}
