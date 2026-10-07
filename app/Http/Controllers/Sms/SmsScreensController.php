<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Gateways\GatewayAvailability;
use App\Domain\Sms\Routing\RoutingTable;
use App\Domain\Sms\Support\PhoneMask;
use App\Http\Controllers\Controller;
use App\Models\SmsGateway;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PageCapabilities;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Doc 14 §8/§9/§13/§14 (S14 step 13) — the [B14-batch] Backoffice PAGES. Each
 * page's initial dataset is THE JSON DOOR'S OWN RESPONSE (the step-3…11
 * controllers, called in-process) so the screen and the API can never drift;
 * every mutation goes back through those same doors from the browser. Reads
 * are gated by the STOP-A keys on the route; phones are masked at the source
 * (SEC-02). The settings page writes through the ONE settings door below
 * (settings.edit + tenant.setting_changed, the CSAT precedent).
 */
final class SmsScreensController extends Controller
{
    public function __construct(
        private readonly PageCapabilities $capabilities,
        private readonly SettingsRepository $settings,
        private readonly RoutingTable $routing,
    ) {}

    public function templates(Request $request): InertiaResponse
    {
        /** @var array<string, mixed> $doors */
        $doors = app(SmsTemplatesController::class)->index()->getData(true);

        $variables = [];
        foreach (\App\Domain\Sms\Catalog\TemplateEvents::all() as $event) {
            $variables[$event] = \App\Domain\Sms\Catalog\TemplateEvents::allowedVariables($event);
        }

        return Inertia::render('Admin/Sms/Templates', $this->base($request, 'Admin/Sms/Templates') + [
            'doors' => $doors,
            'cost_cards' => $this->costCards(),
            'variables' => $variables,
        ]);
    }

    public function gateways(Request $request): InertiaResponse
    {
        /** @var array<string, mixed> $doors */
        $doors = app(SmsGatewaysController::class)->index($request)->getData(true);

        return Inertia::render('Admin/Sms/Gateways', $this->base($request, 'Admin/Sms/Gateways') + [
            'doors' => $doors,
            'today' => $this->todayByGateway(),
        ]);
    }

    public function queue(Request $request): InertiaResponse
    {
        $state = $request->query('state');
        $event = $request->query('event');
        $q = DB::table('sms_messages')->orderByDesc('queued_at')->limit(100);
        if (is_string($state) && $state !== '') {
            $q->where('state', $state);
        }
        if (is_string($event) && $event !== '') {
            $q->where('event', $event);
        }
        $rows = [];
        /** @var object{id: string, state: string, event: string, phone: string, segments: int, is_test: bool, queued_at: ?string, terminal_at: ?string, sent_at: ?string, fail_reason: ?string, skip_reason: ?string} $row */
        foreach ($q->get(['id', 'state', 'event', 'phone', 'segments', 'is_test', 'queued_at', 'terminal_at', 'sent_at', 'fail_reason', 'skip_reason']) as $row) {
            $rows[] = [
                'id' => $row->id,
                'state' => $row->state,
                'event' => $row->event,
                'phone_masked' => PhoneMask::display($row->phone),
                'segments' => (int) $row->segments,
                'is_test' => (bool) $row->is_test,
                'queued_at' => $row->queued_at,
                'last_change_at' => $row->terminal_at ?? $row->sent_at ?? $row->queued_at,
                'reason' => $row->fail_reason ?? $row->skip_reason,
            ];
        }
        /** @var array<string, mixed> $held */
        $held = app(SmsHeldController::class)->index($request)->getData(true);

        return Inertia::render('Admin/Sms/Queue', $this->base($request, 'Admin/Sms/Queue') + [
            'rows' => $rows,
            'held' => $held,
            'filters' => ['state' => is_string($state) ? $state : '', 'event' => is_string($event) ? $event : ''],
        ]);
    }

    public function optouts(Request $request): InertiaResponse
    {
        /** @var array<string, mixed> $doors */
        $doors = app(SmsOptOutsController::class)->index($request)->getData(true);

        return Inertia::render('Admin/Sms/OptOuts', $this->base($request, 'Admin/Sms/OptOuts') + ['doors' => $doors]);
    }

    public function broadcasts(Request $request): InertiaResponse
    {
        /** @var array<string, mixed> $doors */
        $doors = app(SmsBroadcastsController::class)->index($request)->getData(true);
        /** @var array<string, mixed> $templates */
        $templates = app(SmsTemplatesController::class)->index()->getData(true);

        return Inertia::render('Admin/Sms/Broadcasts', $this->base($request, 'Admin/Sms/Broadcasts') + [
            'doors' => $doors,
            'templates' => $templates,
            'cost_cards' => $this->costCards(),
        ]);
    }

    public function settings(Request $request): InertiaResponse
    {
        $events = [];
        foreach (\App\Domain\Sms\Catalog\SendableEvent::cases() as $case) {
            $events[$case->value] = (bool) $this->settings->get('sms.events.'.$case->value.'.enabled');
        }
        /** @var array<string, mixed> $quiet the registry validates the {start, end} shape on write */
        $quiet = (array) $this->settings->get('sms.quiet_hours');
        $start = $quiet['start'] ?? null;
        $end = $quiet['end'] ?? null;
        /** @var object{timezone_name: ?string}|null $tenant */
        $tenant = DB::table('tenants')->where('id', app(\App\Support\Tenancy\TenantContext::class)->tenantId())->first(['timezone_name']);
        $spend = app(\App\Domain\Sms\Cost\CostMeter::class)->monthlySpend();
        $cap = $this->settings->get('sms.broadcast.monthly_cap');

        return Inertia::render('Admin/Sms/Settings', $this->base($request, 'Admin/Sms/Settings') + [
            'quiet' => ['start' => is_string($start) ? $start : '', 'end' => is_string($end) ? $end : '', 'tz' => (string) ($tenant->timezone_name ?? 'UTC')],
            'events' => $events,
            'quota' => ['segments_used' => $spend['segments_used'], 'segments_limit' => $spend['segments_limit']],
            'broadcast_cap' => is_numeric($cap) ? (int) $cap : 0,
            'checklist' => $this->checklist(),
        ]);
    }

    /** The settings WRITE door (quiet hours + the per-event toggles), the CSAT idiom. */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            /** @var array{quiet_hours?: array{start: string, end: string}, events?: array<string, bool>} $input */
            $input = $request->validate([
                'quiet_hours' => ['sometimes', 'array:start,end'],
                'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
                'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
                'events' => ['sometimes', 'array'],
                'events.*' => ['boolean'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $user */
        $user = $request->user();
        if (isset($input['quiet_hours'])) {
            $this->settings->set('sms.quiet_hours', $input['quiet_hours'], actorId: $user->identifier());
        }
        $known = array_map(static fn (\App\Domain\Sms\Catalog\SendableEvent $c): string => $c->value, \App\Domain\Sms\Catalog\SendableEvent::cases());
        foreach ($input['events'] ?? [] as $event => $enabled) {
            if (! in_array($event, $known, true)) {
                return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'unknown event', errors: ['events' => [$event]]);
            }
            $this->settings->set('sms.events.'.$event.'.enabled', (bool) $enabled, actorId: $user->identifier());
        }

        return new JsonResponse(['saved' => true]);
    }

    /** @return array<string, mixed> */
    private function base(Request $request, string $page): array
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $screens */
        $screens = __('sms.screens');

        return [
            // the §9 state vocabulary (sms.chip) rides along for the queue/held rows
            'copy' => $screens + ['chips' => __('sms.chip')],
            'can' => $this->capabilities->for($user, $page),
        ];
    }

    /**
     * §8's setup checklist — "SMS is off until a gateway exists".
     *
     * @return array{gateway: bool, route: bool, templates: bool, incomplete_events: list<string>}
     */
    private function checklist(): array
    {
        $resolution = $this->routing->resolve('+251900000000');
        /** @var array<string, mixed> $templates */
        $templates = app(SmsTemplatesController::class)->index()->getData(true);
        /** @var list<string> $incomplete */
        $incomplete = is_array($templates['incomplete'] ?? null) ? $templates['incomplete'] : [];

        return [
            'gateway' => app(GatewayAvailability::class)->exists(),
            'route' => $resolution->viable() !== [],
            'templates' => $incomplete === [],
            'incomplete_events' => $incomplete,
        ];
    }

    /**
     * The templates editor's cost row: the gateway chain that would serve a
     * +251 send, each with ITS OWN longest-prefix card (the pipeline's read).
     *
     * @return list<array{gateway: string, per_segment: string, currency: string}>
     */
    private function costCards(): array
    {
        $cards = [];
        foreach ($this->routing->resolve('+251900000000')->viable() as $hop) {
            $gateway = SmsGateway::query()->find($hop['gateway_id']);
            if ($gateway === null) {
                continue;
            }
            $card = \App\Domain\Sms\Gateways\GatewayConfig::fromStored($gateway->config)->rateCardFor('+251900000000');
            if ($card !== null) {
                $cards[] = ['gateway' => (string) $gateway->name, 'per_segment' => $card->pricePerSegment, 'currency' => $card->currency];
            }
        }

        return $cards;
    }

    /**
     * Today's sent count per gateway (the card's sparkline figure), DB-day in UTC.
     *
     * @return array<string, int>
     */
    private function todayByGateway(): array
    {
        $out = [];
        /** @var object{gateway_id: string, n: int} $row */
        foreach (DB::table('sms_messages')->whereNotNull('gateway_id')->where('is_test', false)->whereRaw("sent_at >= date_trunc('day', clock_timestamp())")->groupBy('gateway_id')->selectRaw('gateway_id, count(*) AS n')->get() as $row) {
            $out[$row->gateway_id] = (int) $row->n;
        }

        return $out;
    }
}
