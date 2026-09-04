<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Webchat\Health\WidgetHealth;
use App\Domain\Webchat\Visitor\VisitorConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Doc 13 T-08 `POST /api/widget/v1/beacon` — the loader's 1×1-cost beacons
 * (load, open, blocked-origin, mount-fail, double-install) and the panel's
 * sampled error stacks, all onto the widget's per-day COUNTERS
 * (widget_health_counters, C2: the day bucket computed in SQL from the DB
 * clock at the tenant's display timezone) with a SCRUBBED sample at
 * `widget.beacon.sample_rate`. No third-party analytics; no PII: the
 * scrubber is Doc 09 SEC-03's.
 *
 * The LOADER's beacons arrive BEFORE any session exists (a blocked origin has
 * no visitor), so this door sits OUTSIDE the visitor guard — widget key +
 * CORS only, the /session shape — and answers 204 to everything well-formed
 * and 204 to everything not (a beacon never errors a visitor: NFR-06). It is
 * throttled by the widget key's own quota.
 */
final class WidgetBeaconController
{
    public function __construct(
        private readonly VisitorConversation $conversations,
        private readonly WidgetHealth $health,
    ) {}

    public function store(Request $request): SymfonyResponse
    {
        $widget = $this->conversations->widgetForBeacon($request);
        if ($widget === null || $widget->deleted_at !== null) {
            return new Response('', 204); // dead to the wire (C3): silence, never a hint
        }

        $kind = $request->input('kind');
        $reason = $request->input('reason');
        $sample = $request->input('sample');
        $bundle = $request->input('bundle_hash');
        $origin = $request->headers->get('Origin');

        if (! is_string($kind) || ! in_array($kind, WidgetHealth::KINDS, true)) {
            return new Response('', 204);
        }

        $this->health->beacon(
            $widget,
            $kind,
            is_string($reason) ? substr($reason, 0, 64) : null,
            is_string($sample) ? $sample : null,
            is_string($bundle) ? substr($bundle, 0, 64) : null,
            is_string($origin) ? $origin : null,
        );

        return new Response('', 204);
    }
}
