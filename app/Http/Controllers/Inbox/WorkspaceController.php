<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Health\ChannelHealthState;
use App\Domain\Inbox\ReadModels\ProjectorStatus;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Domain\Inbox\Views\ViewVariants;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The B1-S01 shell's page door (S07 step 8) — GET /inbox renders the
 * workspace with everything the FIRST paint needs so the shell is useful
 * before a single realtime frame arrives: the seven system views with their
 * per-viewer channel hashes and TRUE counts (T-03: server ZCARD, never a
 * client derivation), the viewer's saved views, the prefs blob (FR-01
 * persisted region sizes, §9 density, FR-08 view order), the FR-03 health
 * rollup (step 3's ChannelHealthState — the indicator renders state, it
 * never infers it), and the FR-30 build id.
 *
 * `tickets.view` — a pure READ of what the viewer could already see; the
 * R-26 principle governs the WRITE doors, not this one.
 */
final class WorkspaceController extends Controller
{
    /** @param array{conversation_id: string, ticket_id: string|null}|null $initialThread */
    public function show(Request $request, ?array $initialThread = null): Response
    {
        $context = app(TenantContext::class);
        $viewHash = app(ViewHash::class);
        $variants = app(ViewVariants::class);
        $zsets = app(ViewZsets::class);
        $health = app(ChannelHealthState::class);
        $projector = app(ProjectorStatus::class);
        $capabilities = app(CapabilityMapProvider::class);

        /** @var User $user */
        $user = $request->user();

        $views = [];
        foreach ([...ViewHash::PER_USER_VIEWS, ...ViewHash::SHARED_VIEWS] as $view) {
            $zsetKey = $viewHash->zsetKeyForViewer($view, $user);
            if (str_contains($zsetKey, ':scope:')) {
                // A-30: a narrower viewer reads their lazy variant — ensure
                // materializes it on first touch, then the projector patches it.
                [$base, $signature] = explode(':scope:', $zsetKey, 2);
                $zsetKey = $variants->ensure($base, $signature);
            }

            $views[] = [
                'key' => $view,
                'hash' => $viewHash->forViewer($view, $user),
                'count' => $zsets->count($zsetKey),
            ];
        }

        /** @var list<object{id: string, name: string, owner_user_id: string, shared_to_type: string|null}> $saved */
        $saved = DB::table('inbox_saved_views')
            ->whereNull('deleted_at')
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($user): void {
                $query->where('owner_user_id', $user->identifier())
                    ->orWhereNotNull('shared_to_type');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id', 'shared_to_type'])
            ->all();

        /** @var string|null $prefs */
        $prefs = DB::table('inbox_user_prefs')->where('user_id', $user->identifier())->value('prefs');

        $map = $capabilities->mapFor($user);

        $states = $health->all();

        $tenantName = DB::table('tenants')->where('id', $context->tenantId())->value('name');
        $tenantName = is_string($tenantName) ? $tenantName : '';

        return Inertia::render('Inbox/Workspace', [
            // C-08: every visible string is an i18n key resolved server-side
            // (user language → tenant default → English).
            't' => __('inbox'),
            'tenantName' => $tenantName,
            'views' => $views,
            'savedViews' => array_map(fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'own' => $row->owner_user_id === $user->identifier(),
                'shared' => $row->shared_to_type !== null,
            ], $saved),
            'prefs' => $prefs !== null ? json_decode($prefs, true, 16, JSON_THROW_ON_ERROR) : (object) [],
            'health' => [
                'states' => (object) $states,
                // FR-03 rollup (Doc 05 FR-29): the dot shows the WORST state.
                'worst' => $this->worst($states),
            ],
            'projectorStaleSeconds' => $projector->staleSeconds(),
            'tenantId' => $context->tenantId(),
            'buildId' => is_string($build = config('app.build_id')) ? $build : 'dev',
            'can' => [
                'views_manage' => $map->allows('views.manage'),
                'views_share' => $map->allows('views.share'),
                'tickets_create' => $map->allows('tickets.create'),
            ],
            'tabBadges' => $this->tabBadges($request),
            'initialThread' => $initialThread,
            // N-19: the flag hides the RENDER only (FR-18's voice button).
            'features' => [
                'voice_recording' => app(\App\Support\Flags\FeatureRegistry::class)->active('inbox.voice_recording'),
            ],
        ]);
    }

    /**
     * FR-15 deep link /inbox/t/{ticket}: resolves THROUGH the viewer's scope;
     * missing and out-of-scope answer the SAME 404-pattern page with "search
     * instead" (EC-01/C-10 — sabotage #15 makes them distinguishable and the
     * proof goes red). HTTP 404 on the page response itself: no existence
     * oracle in the status line either.
     */
    public function openTicket(Request $request, string $ticketId): \Symfony\Component\HttpFoundation\Response
    {
        /** @var User $user */
        $user = $request->user();

        $query = \App\Models\TicketListItem::query()->where('ticket_id', $ticketId);
        app(\App\Support\Rbac\Scopes\ScopeSets::class)->apply($query, 'tickets', $user, \App\Domain\Inbox\ReadModels\ListReadModel::columns());
        /** @var \App\Models\TicketListItem|null $row */
        $row = $query->first();

        if ($row === null) {
            return $this->notFoundPatternPage($request);
        }

        $conversationIdRaw = $row->getAttribute('conversation_id');

        return $this->show($request, [
            'conversation_id' => is_string($conversationIdRaw) ? $conversationIdRaw : '',
            'ticket_id' => $ticketId,
        ])->toResponse($request);
    }

    /** FR-15 deep link /inbox/c/{conversation} — same pattern, same 404 shape. */
    public function openConversation(Request $request, string $conversationId): \Symfony\Component\HttpFoundation\Response
    {
        /** @var User $user */
        $user = $request->user();

        $query = \App\Models\TicketListItem::query()->where('conversation_id', $conversationId);
        app(\App\Support\Rbac\Scopes\ScopeSets::class)->apply($query, 'tickets', $user, \App\Domain\Inbox\ReadModels\ListReadModel::columns());
        if (! $query->exists()) {
            return $this->notFoundPatternPage($request);
        }

        return $this->show($request, [
            'conversation_id' => $conversationId,
            'ticket_id' => null,
        ])->toResponse($request);
    }

    private function notFoundPatternPage(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        \Illuminate\Support\Facades\Log::info('inbox deep-link 404-pattern served', [
            'metric' => 'security.cross_tenant_probe',
        ]);

        return Inertia::render('Inbox/NotFoundPattern', ['t' => __('inbox.notfound')])
            ->toResponse($request)
            ->setStatusCode(404);
    }

    /**
     * §9 badge dots — Doc 08 (Customer: RG banner presence) and Doc 09
     * (Copilot: new suggestion) OWN these signals; neither document is built.
     * Production answer: no dots. In local/ci ONLY, `?seam_badges=1` loads
     * the two SEAM FIXTURES so the browser suite proves the rendering
     * contract the owning docs will drive (the ?lang override precedent).
     *
     * @return array{customer: bool, copilot: bool}
     */
    private function tabBadges(Request $request): array
    {
        $badges = ['customer' => false, 'copilot' => false];

        if ($request->query('seam_badges') === '1' && app()->environment(['local', 'testing', 'ci'])) {
            foreach (['doc08-customer-badge', 'doc09-copilot-badge'] as $fixture) {
                /** @var array<string, mixed> $seam */
                $seam = json_decode(
                    (string) file_get_contents(base_path('fixtures/inbox/seams/'.$fixture.'.json')),
                    true,
                    8,
                    JSON_THROW_ON_ERROR,
                );
                $badges['customer'] = (bool) ($seam['customer'] ?? $badges['customer']);
                $badges['copilot'] = (bool) ($seam['copilot'] ?? $badges['copilot']);
            }
        }

        return $badges;
    }

    /** @param array<string, string> $states */
    private function worst(array $states): string
    {
        if ($states === []) {
            // Empty state store = nothing reported yet. "unknown", NEVER a
            // fabricated healthy (the ChannelHealthState contract).
            return 'unknown';
        }

        foreach (['down', 'degraded'] as $bad) {
            if (in_array($bad, $states, true)) {
                return $bad;
            }
        }

        return 'healthy';
    }
}
