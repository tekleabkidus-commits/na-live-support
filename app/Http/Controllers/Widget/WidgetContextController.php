<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Visitor\VisitorConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 FR-24 `POST /api/widget/v1/context` — the page context the visitor's
 * panel captured, attached to the visitor's OWN conversation for staff
 * (Doc 07's Customer/Ticket panels read it through a Doc 13 door).
 *
 * THE STRUCTURAL CLAUSE: capture() is the ONLY inbound channel for operator
 * keys and it is allowlist-filtered SERVER-SIDE — an undeclared key is
 * DROPPED before the row is touched (never stored, never logged), so the
 * loader's "no cookies, no localStorage reads, no form scraping" is a
 * falsification on two sides: the loader has no such code path (the
 * footprint audit, step 7) and the server keeps nothing it did not declare
 * (sabotage (k)). One row per conversation, latest wins; `captured_at` is
 * the trigger's (R-20). No conversation yet (no message sent) → 204 and
 * nothing stored: a bounce leaves no row (FR-05's spirit).
 */
final class WidgetContextController
{
    private const MAX_TEXT = 2048;

    public function __construct(
        private readonly VisitorConversation $conversations,
        private readonly WidgetConfigReader $reader,
    ) {}

    public function store(Request $request): SymfonyResponse
    {
        $visitor = $request->user('widget');
        if (! $visitor instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }
        $widget = $this->conversations->widgetFor($request);

        /** @var array{url?: string|null, referrer?: string|null, title?: string|null, browser?: string|null, os?: string|null, device?: string|null, viewport?: array{w?: int|null, h?: int|null}, language?: string|null, tz_offset_minutes?: int|null, custom?: array<mixed>, conversation_id?: string|null} $input */
        $input = $request->validate([
            'url' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'referrer' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'title' => ['nullable', 'string', 'max:512'],
            'browser' => ['nullable', 'string', 'max:64'],
            'os' => ['nullable', 'string', 'max:64'],
            'device' => ['nullable', 'string', 'max:32'],
            'viewport' => ['sometimes', 'array'],
            'viewport.w' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'viewport.h' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'language' => ['nullable', 'string', 'max:16'],
            'tz_offset_minutes' => ['nullable', 'integer', 'min:-840', 'max:840'],
            'custom' => ['sometimes', 'array'],
            'conversation_id' => ['nullable', 'string', 'max:64'],
        ]);

        $conversation = $this->conversations->find($widget, $visitor);
        if ($conversation === null) {
            return new Response('', 204);
        }
        // A client naming a conversation must name its OWN — anything else is absent (AC-04).
        $named = $input['conversation_id'] ?? null;
        if (is_string($named) && $named !== $conversation->identifier()) {
            throw new NotFoundHttpException;
        }

        $config = $this->reader->live($widget)['config'];
        $behavior = is_array($config['behavior'] ?? null) ? $config['behavior'] : [];
        $allowlist = is_array($behavior['context_allowlist'] ?? null) ? $behavior['context_allowlist'] : [];
        $custom = [];
        $submitted = is_array($input['custom'] ?? null) ? $input['custom'] : [];
        foreach ($submitted as $key => $value) {
            // Undeclared keys are DROPPED here — the structural filter (FR-24).
            if (is_string($key) && in_array($key, $allowlist, true) && is_scalar($value)) {
                $custom[$key] = is_string($value) ? Str::limit($value, 256, '') : $value;
            }
        }

        $row = [
            'url' => $input['url'] ?? null,
            'referrer' => $input['referrer'] ?? null,
            'title' => $input['title'] ?? null,
            'browser' => $input['browser'] ?? null,
            'os' => $input['os'] ?? null,
            'device' => $input['device'] ?? null,
            'viewport_w' => is_array($input['viewport'] ?? null) ? ($input['viewport']['w'] ?? null) : null,
            'viewport_h' => is_array($input['viewport'] ?? null) ? ($input['viewport']['h'] ?? null) : null,
            'language' => $input['language'] ?? null,
            'tz_offset_minutes' => $input['tz_offset_minutes'] ?? null,
            'custom' => json_encode($custom, JSON_THROW_ON_ERROR),
        ];

        // UPDATE carries updated_at (granted); INSERT does not — the hygiene pair is the
        // database's on INSERT (R-19b: app-DENIED, DEFAULT clock_timestamp()).
        $updated = DB::table('widget_visitor_contexts')
            ->where('conversation_id', $conversation->identifier())
            ->update($row + ['updated_at' => DB::raw('clock_timestamp()')]);
        if ($updated === 0) {
            DB::table('widget_visitor_contexts')->insert($row + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'conversation_id' => $conversation->identifier(),
                'visitor_id' => $visitor->visitorId, // set at birth only — immutable by grant (SCHEMA AMENDMENT 7 item 3)
            ]);
        }

        return new Response('', 204);
    }
}
