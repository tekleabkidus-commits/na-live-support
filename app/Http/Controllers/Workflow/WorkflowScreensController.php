<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Actions\RunAiTaskAction;
use App\Domain\Automation\DryRun\DryRunner;
use App\Domain\Automation\ExecutionLog\LogReader;
use App\Domain\Automation\Rules\KeywordSets;
use App\Domain\Automation\Rules\RuleWriter;
use App\Domain\Automation\Rules\SystemActorFloor;
use App\Domain\Automation\Webhooks\WebhookDestinationRefused;
use App\Domain\Automation\Webhooks\WebhookDestinations;
use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\WhereUsed;
use App\Domain\Forms\FormPublisher;
use App\Domain\Forms\FormWriter;
use App\Domain\Forms\UsageBanner;
use App\Domain\Hours\CalendarWriter;
use App\Domain\Macros\MacroWriter;
use App\Domain\Macros\PaletteReader;
use App\Domain\Sla\ExampleSentence;
use App\Domain\Sla\Policies\PolicyWriter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PageCapabilities;
use App\Support\Settings\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Doc 12 §8/§9 (S12 step 14) — Backoffice → Workflow: Doc 12's OWN surfaces
 * over the step-1…13 doors, and NOTHING else. Every mutation here is a call
 * into a writer that audits + events itself (NFR-06: zero configuration
 * change is silent); this controller validates the request shape, carries
 * the operator's one question to the door, and hands the refusal back as
 * RFC 9457 problem details (C-04). Reads are row reads under RLS. Pages
 * render functional/themed/i18n-keyed from design-system components and ride
 * [B12-batch] until their design freezes (R-D12-2.2). The chrome is the
 * drawn Backoffice shell the Organization page carries; the shell-side links
 * back are packet P-NAV's.
 */
final class WorkflowScreensController extends Controller
{
    public function __construct(
        private readonly PageCapabilities $capabilities,
        private readonly SettingsRepository $settings,
    ) {}

    // ── hub ──────────────────────────────────────────────────────────────

    public function hub(Request $request): Response
    {
        return Inertia::render('Workflow/Hub', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Hub'),
            'paused' => (bool) \Laravel\Pennant\Feature::active('automation.rules_pause'),
            'counts' => [
                'rules' => DB::table('automation_rules')->count(),
                'policies' => DB::table('sla_policies')->where('active', true)->count(),
                'forms' => DB::table('forms')->where('active', true)->count(),
                'macros' => DB::table('macros')->where('active', true)->count(),
            ],
        ]);
    }

    // ── rules ────────────────────────────────────────────────────────────

    public function rules(Request $request, LogReader $log, SystemActorFloor $floor): Response
    {
        $user = $this->user($request);
        $rows = [];
        foreach (DB::table('automation_rules')->orderBy('position')->orderBy('id')->get() as $rule) {
            $summary = $log->forRule(self::s($rule->id), $user, 1);
            $rows[] = [
                'id' => $rule->id, 'name' => $rule->name, 'description' => $rule->description, 'trigger' => $rule->trigger,
                'position' => self::i($rule->position), 'active' => (bool) $rule->active,
                'has_draft' => $this->hasDraft(self::s($rule->id), $rule->active_revision_id),
                'matched_count' => $summary['matched_count'], 'last_run_at' => $summary['last_run_at'],
                'guard_trips' => count(array_filter($summary['executions'], static fn (array $x): bool => ($x['guard_trips'] ?? []) !== [])),
                'drift' => (bool) $rule->active ? $floor->driftFor(self::s($rule->id)) : [],
                'updated_at' => $rule->updated_at,
            ];
        }

        return Inertia::render('Workflow/Rules', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($user, 'Workflow/Rules'),
            'rules' => $rows,
            'triggers' => array_keys(\App\Domain\Automation\Engine\TriggerMap::EVENTS),
        ]);
    }

    public function ruleEditor(Request $request, string $ruleId, LogReader $log, DryRunner $dryRun): Response
    {
        $user = $this->user($request);
        $rule = DB::table('automation_rules')->where('id', $ruleId)->first();
        if ($rule === null) {
            abort(404);
        }
        $latest = DB::table('automation_rule_revisions')->where('rule_id', $ruleId)->orderByDesc('created_at')->orderByDesc('id')->first();
        /** @var array<string, mixed> $definition */
        $definition = $latest !== null ? (json_decode(self::s($latest->definition), true, 32) ?: []) : [];

        return Inertia::render('Workflow/RuleEditor', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($user, 'Workflow/RuleEditor'),
            'rule' => [
                'id' => $rule->id, 'name' => $rule->name, 'description' => $rule->description, 'trigger' => $rule->trigger,
                'position' => self::i($rule->position), 'active' => (bool) $rule->active, 'cooldown_seconds' => self::i($rule->cooldown_seconds),
                'schedule_cron' => $rule->schedule_cron, 'schedule_next_run_at' => $rule->schedule_next_run_at, 'run_window' => $rule->run_window !== null ? json_decode(self::s($rule->run_window), true, 8) : null,
                'active_revision_id' => $rule->active_revision_id, 'latest_revision_id' => $latest->id ?? null,
                'has_draft' => $this->hasDraft($ruleId, $rule->active_revision_id), 'updated_at' => $rule->updated_at,
            ],
            'definition' => ['conditions' => $definition['conditions'] ?? ['all' => []], 'actions' => $definition['actions'] ?? []],
            'triggers' => array_keys(\App\Domain\Automation\Engine\TriggerMap::EVENTS),
            'action_types' => ActionRegistry::TYPES,
            'ai_tasks' => RunAiTaskAction::TASKS,
            'keyword_sets' => DB::table('automation_keyword_sets')->orderBy('name')->get(['id', 'name', 'active'])->all(),
            'destinations' => DB::table('automation_webhook_destinations')->orderBy('name')->get(['id', 'name', 'url', 'active', 'auto_disabled_at'])->all(),
            'macros' => DB::table('macros')->where('active', true)->orderBy('name')->get(['id', 'name', 'scope'])->all(),
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('position')->get(['id', 'name'])->all(),
            'queues' => DB::table('queues')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id'])->all(),
            'fields' => DB::table('field_definitions')->where('active', true)->orderBy('entity')->orderBy('position')->get(['id', 'entity', 'key', 'label', 'type'])->all(),
            'log' => $log->forRule($ruleId, $user, 25),
            'recent_events' => $dryRun->recentEvents(50),
        ]);
    }

    public function createRule(Request $request, RuleWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            $definition = $this->ruleDefinition($request);
            $made = $writer->create($definition, $this->user($request));

            return ['ok' => true, 'rule_id' => $made['rule_id'], 'revision_id' => $made['revision_id']];
        });
    }

    public function updateRule(Request $request, string $ruleId, RuleWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $ruleId, $writer): array {
            $definition = $this->ruleDefinition($request);
            $revisionId = $writer->edit($ruleId, $definition, $this->user($request));

            return ['ok' => true, 'revision_id' => $revisionId];
        });
    }

    public function activateRule(Request $request, string $ruleId, RuleWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $ruleId, $writer): array {
            $revisionId = self::s($request->input('revision_id', ''));
            if ($revisionId === '') {
                $revisionId = self::s(DB::table('automation_rule_revisions')->where('rule_id', $ruleId)->orderByDesc('created_at')->orderByDesc('id')->value('id'));
            }
            $writer->activate($ruleId, $revisionId, $this->user($request));

            return ['ok' => true];
        });
    }

    public function deactivateRule(Request $request, string $ruleId, RuleWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $ruleId, $writer): array {
            $writer->deactivate($ruleId, $this->user($request));

            return ['ok' => true];
        });
    }

    public function reorderRule(Request $request, string $ruleId, RuleWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $ruleId, $writer): array {
            $position = self::i($request->input('position', 0));
            if ($position < 1) {
                throw new InvalidArgumentException('position must be ≥ 1');
            }
            $writer->reposition($ruleId, $position, $this->user($request));

            return ['ok' => true];
        });
    }

    public function dryRun(Request $request, DryRunner $dryRun): JsonResponse
    {
        return $this->door(function () use ($request, $dryRun): array {
            $eventId = self::s($request->input('event_id', ''));
            /** @var array<string, mixed> $definition */
            $definition = is_array($request->input('definition')) ? $request->input('definition') : [];
            /** @var array<string, mixed> $conditions */
            $conditions = is_array($definition['conditions'] ?? null) ? $definition['conditions'] : ['all' => []];
            /** @var list<array<string, mixed>> $actions */
            $actions = is_array($definition['actions'] ?? null) ? array_values($definition['actions']) : [];
            $facts = $eventId !== '' ? $dryRun->contextForEvent($eventId) : [];
            $result = $dryRun->run(['conditions' => $conditions, 'actions' => $actions], $facts, null, $this->user($request));

            return ['ok' => true] + $result;
        });
    }

    public function createKeywordSet(Request $request, KeywordSets $sets): JsonResponse
    {
        return $this->door(function () use ($request, $sets): array {
            $id = $sets->create(self::s($request->input('name', '')), self::stringList($request->input('keywords')), $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateKeywordSet(Request $request, string $setId, KeywordSets $sets): JsonResponse
    {
        return $this->door(function () use ($request, $setId, $sets): array {
            $changes = [];
            if ($request->has('name')) {
                $changes['name'] = self::s($request->input('name'));
            }
            if ($request->has('keywords')) {
                $changes['keywords'] = self::stringList($request->input('keywords'));
            }
            if ($request->has('active')) {
                $changes['active'] = (bool) $request->input('active');
            }
            $sets->update($setId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function createDestination(Request $request, WebhookDestinations $destinations): JsonResponse
    {
        return $this->door(function () use ($request, $destinations): array {
            $id = $destinations->create(self::s($request->input('name', '')), self::s($request->input('url', '')), $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateDestination(Request $request, string $destinationId, WebhookDestinations $destinations): JsonResponse
    {
        return $this->door(function () use ($request, $destinationId, $destinations): array {
            $changes = [];
            if ($request->has('name')) {
                $changes['name'] = self::s($request->input('name'));
            }
            if ($request->has('active')) {
                $changes['active'] = (bool) $request->input('active');
            }
            $destinations->update($destinationId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    // ── SLA ──────────────────────────────────────────────────────────────

    public function sla(Request $request): Response
    {
        $policies = [];
        foreach (DB::table('sla_policies')->orderBy('position')->orderBy('id')->get() as $p) {
            $policies[] = [
                'id' => $p->id, 'name' => $p->name, 'position' => self::i($p->position), 'active' => (bool) $p->active,
                'match_conditions' => json_decode(self::s($p->match_conditions), true, 8) ?: [],
                'targets' => json_decode(self::s($p->targets), true, 8) ?: [],
                'clock_mode' => $p->clock_mode, 'calendar_id' => $p->calendar_id, 'warning_pct' => self::i($p->warning_pct),
                'pause_conditions' => json_decode(self::s($p->pause_conditions), true, 8) ?: [],
                'retarget_mode' => $p->retarget_mode,
                'breach_escalation' => $p->breach_escalation !== null ? json_decode(self::s($p->breach_escalation), true, 8) : null,
                'updated_at' => $p->updated_at,
            ];
        }

        return Inertia::render('Workflow/Sla', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Sla'),
            'policies' => $policies,
            'calendars' => DB::table('business_calendars')->where('active', true)->orderBy('name')->get(['id', 'name', 'timezone', 'is_tenant_default', 'department_id'])->all(),
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('position')->get(['id', 'name'])->all(),
            'timezone' => $this->tenantTimezone(),
        ]);
    }

    public function createPolicy(Request $request, PolicyWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            /** @var array{name: string, match_conditions?: array<string, mixed>, targets: array<string, array<string, int>>, clock_mode: string, calendar_id?: ?string, warning_pct?: int, pause_conditions?: array<string, bool>, retarget_mode?: string, breach_escalation?: ?array{path: string, reason: string}} $definition */
            $definition = $this->policyDefinition($request);
            $id = $writer->create($definition, $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updatePolicy(Request $request, string $policyId, PolicyWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $policyId, $writer): array {
            $changes = $this->policyDefinition($request, partial: true);
            $writer->update($policyId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function slaExample(Request $request, ExampleSentence $example, CalendarWriter $calendars): JsonResponse
    {
        return $this->door(function () use ($request, $example, $calendars): array {
            $calendarId = self::s($request->input('calendar_id', ''));
            $calendar = $calendarId !== '' ? $calendars->snapshot($calendarId) : null;
            $target = max(60, self::i($request->input('target_seconds', 2400)));
            $createdAt = new DateTimeImmutable(self::s($request->input('created_at', '2026-09-01T17:00:00+03:00')));
            $tz = new DateTimeZone($this->tenantTimezone());

            return ['ok' => true, 'sentence' => $example->render($calendar, $target, $createdAt, $tz, app()->getLocale())];
        });
    }

    // ── hours ────────────────────────────────────────────────────────────

    public function hours(Request $request): Response
    {
        $calendars = [];
        foreach (DB::table('business_calendars')->orderByDesc('is_tenant_default')->orderBy('name')->get() as $c) {
            $calendars[] = [
                'id' => $c->id, 'name' => $c->name, 'timezone' => $c->timezone, 'is_tenant_default' => (bool) $c->is_tenant_default,
                'department_id' => $c->department_id, 'active' => (bool) $c->active,
                'weekdays' => json_decode(self::s($c->weekdays), true, 8) ?: [],
                'holidays' => DB::table('business_calendar_holidays')->where('calendar_id', $c->id)->orderBy('date')->get(['id', 'date', 'name', 'recurring_yearly'])->all(),
                'updated_at' => $c->updated_at,
            ];
        }

        return Inertia::render('Workflow/Hours', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Hours'),
            'calendars' => $calendars,
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('position')->get(['id', 'name'])->all(),
            'timezone' => $this->tenantTimezone(),
        ]);
    }

    public function createCalendar(Request $request, CalendarWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            /** @var array<int|string, list<array{open: string, close: string}>> $weekdays */
            $weekdays = is_array($request->input('weekdays')) ? $request->input('weekdays') : [];
            $departmentId = $request->input('department_id');
            $id = $writer->create(
                self::s($request->input('name', '')), self::s($request->input('timezone', $this->tenantTimezone())), $weekdays,
                $this->user($request)->identifier(), (bool) $request->input('is_tenant_default', false), is_string($departmentId) && $departmentId !== '' ? $departmentId : null,
            );

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateCalendar(Request $request, string $calendarId, CalendarWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $calendarId, $writer): array {
            $changes = [];
            foreach (['name', 'timezone', 'weekdays', 'active'] as $key) {
                if ($request->has($key)) {
                    $changes[$key] = $request->input($key);
                }
            }
            /** @var array{name?: string, timezone?: string, weekdays?: array<int|string, list<array{open: string, close: string}>>, active?: bool} $changes */
            $writer->update($calendarId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function addHoliday(Request $request, string $calendarId, CalendarWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $calendarId, $writer): array {
            $id = $writer->addHoliday($calendarId, self::s($request->input('date', '')), self::s($request->input('name', '')), (bool) $request->input('recurring_yearly', false), $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function removeHoliday(Request $request, string $holidayId, CalendarWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $holidayId, $writer): array {
            $writer->removeHoliday($holidayId, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    // ── macros ───────────────────────────────────────────────────────────

    public function macros(Request $request, PaletteReader $palette): Response
    {
        $user = $this->user($request);
        $rows = [];
        foreach ($palette->search($user) as $row) {
            $rows[] = $row;
        }
        $mine = DB::table('macros')->where('active', true)->get();

        return Inertia::render('Workflow/Macros', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($user, 'Workflow/Macros'),
            'macros' => $mine->map(static fn (object $m): array => [
                'id' => $m->id, 'name' => $m->name, 'scope' => $m->scope, 'kind' => $m->kind, 'body' => $m->body,
                'owner_id' => $m->owner_id, 'team_id' => $m->team_id,
                'variables' => json_decode(self::s($m->variables), true, 8) ?: [],
                'bundled_actions' => $m->bundled_actions !== null ? json_decode(self::s($m->bundled_actions), true, 8) : [],
                'usage_count' => self::i($m->usage_count), 'updated_at' => $m->updated_at,
            ])->values()->all(),
            'palette' => $rows,
            'sample_tickets' => DB::table('tickets')->orderByDesc('created_at')->limit(10)->get(['id', 'number', 'subject'])->all(),
            'variables' => \App\Domain\Macros\VariableResolver::VOCABULARY,
        ]);
    }

    public function createMacro(Request $request, MacroWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            $user = $this->user($request);
            /** @var array{name: string, scope: string, kind?: string, body: string, owner_id?: ?string, team_id?: ?string, bundled_actions?: list<array{type: string, value: mixed}>} $definition */
            $definition = [
                'name' => self::s($request->input('name', '')), 'scope' => self::s($request->input('scope', 'personal')),
                'kind' => self::s($request->input('kind', 'macro')), 'body' => self::s($request->input('body', '')),
                'owner_id' => $request->input('scope', 'personal') === 'personal' ? $user->identifier() : null,
                'team_id' => is_string($request->input('team_id')) ? $request->input('team_id') : null,
            ];
            if (is_array($request->input('bundled_actions'))) {
                $definition['bundled_actions'] = self::bundledList($request->input('bundled_actions'));
            }
            $id = $writer->create($definition, $user->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateMacro(Request $request, string $macroId, MacroWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $macroId, $writer): array {
            $changes = [];
            foreach (['name', 'body', 'kind', 'active', 'bundled_actions'] as $key) {
                if ($request->has($key)) {
                    $changes[$key] = $request->input($key);
                }
            }
            $writer->update($macroId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function previewMacro(Request $request, string $macroId, PaletteReader $palette): JsonResponse
    {
        return $this->door(function () use ($request, $macroId, $palette): array {
            return ['ok' => true] + $palette->preview($macroId, self::s($request->input('ticket_id', '')), $this->user($request));
        });
    }

    // ── fields ───────────────────────────────────────────────────────────

    public function fields(Request $request): Response
    {
        $fields = [];
        foreach (DB::table('field_definitions')->orderBy('entity')->orderBy('position')->orderBy('id')->get() as $f) {
            $fields[] = [
                'id' => $f->id, 'entity' => $f->entity, 'key' => $f->key, 'label' => json_decode(self::s($f->label), true, 4) ?: [],
                'type' => $f->type, 'validation' => json_decode(self::s($f->validation), true, 8) ?: [],
                'agent_editable' => (bool) $f->agent_editable, 'customer_visible' => (bool) $f->customer_visible,
                'position' => self::i($f->position), 'active' => (bool) $f->active, 'deactivated_at' => $f->deactivated_at,
                'options' => DB::table('field_definition_options')->where('field_id', $f->id)->orderBy('position')->get(['id', 'value', 'label', 'position'])
                    ->map(static fn (object $o): array => ['id' => $o->id, 'value' => $o->value, 'label' => json_decode(self::s($o->label), true, 4) ?: [], 'position' => self::i($o->position)])->values()->all(),
                'updated_at' => $f->updated_at,
            ];
        }

        return Inertia::render('Workflow/Fields', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Fields'),
            'fields' => $fields,
            'types' => DefinitionWriter::TYPES,
            'choice_types' => DefinitionWriter::CHOICE_TYPES,
        ]);
    }

    public function createField(Request $request, DefinitionWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            /** @var array{entity: string, key: string, label: array<string, string>, type: string, validation?: array<string, mixed>, agent_editable?: bool, customer_visible?: bool, options?: list<array{value: string, label: array<string, string>}>} $definition */
            $definition = [
                'entity' => self::s($request->input('entity', 'ticket')), 'key' => self::s($request->input('key', '')),
                'label' => self::stringMap($request->input('label')), 'type' => self::s($request->input('type', 'text')),
                'validation' => is_array($request->input('validation')) ? array_filter($request->input('validation'), static fn (mixed $v, mixed $k): bool => is_string($k), ARRAY_FILTER_USE_BOTH) : [],
                'agent_editable' => (bool) $request->input('agent_editable', true), 'customer_visible' => (bool) $request->input('customer_visible', false),
            ];
            if (is_array($request->input('options'))) {
                $definition['options'] = self::optionList($request->input('options'));
            }
            $id = $writer->create($definition, $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateField(Request $request, string $fieldId, DefinitionWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $fieldId, $writer): array {
            $changes = [];
            foreach (['label', 'validation', 'agent_editable', 'customer_visible', 'position'] as $key) {
                if ($request->has($key)) {
                    $changes[$key] = $request->input($key);
                }
            }
            /** @var array{label?: array<string, string>, validation?: array<string, mixed>, agent_editable?: bool, customer_visible?: bool, position?: int} $changes */
            $writer->update($fieldId, $changes, $this->user($request)->identifier());
            $optionId = $request->input('remove_option_id');
            if (is_string($optionId) && $optionId !== '') {
                $writer->removeOption($optionId, $this->user($request)->identifier());
            }

            return ['ok' => true];
        });
    }

    public function deactivateField(Request $request, string $fieldId, DefinitionWriter $writer): JsonResponse
    {
        return $this->door(fn (): array => ['ok' => true] + $writer->deactivate($fieldId, $this->user($request)->identifier()));
    }

    public function reactivateField(Request $request, string $fieldId, DefinitionWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $fieldId, $writer): array {
            $writer->reactivate($fieldId, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function whereUsed(Request $request, string $entity, string $key, WhereUsed $whereUsed): JsonResponse
    {
        return new JsonResponse(['ok' => true] + $whereUsed->forField($entity, $key));
    }

    // ── forms ────────────────────────────────────────────────────────────

    public function forms(Request $request, UsageBanner $usage): Response
    {
        $forms = [];
        foreach (DB::table('forms')->orderBy('name')->get() as $f) {
            $current = $f->current_version_id !== null ? DB::table('form_versions')->where('id', $f->current_version_id)->first(['version', 'published_at']) : null;
            $forms[] = [
                'id' => $f->id, 'name' => $f->name, 'active' => (bool) $f->active,
                'current_version' => $current->version ?? null, 'published_at' => $current->published_at ?? null,
                'usage' => $usage->forForm(self::s($f->id)), 'updated_at' => $f->updated_at,
            ];
        }

        return Inertia::render('Workflow/Forms', [
            'copy' => __('workflow.ui'),
            'usage_labels' => __('workflow.forms.usage'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Forms'),
            'forms' => $forms,
        ]);
    }

    public function formBuilder(Request $request, string $formId, UsageBanner $usage): Response
    {
        $form = DB::table('forms')->where('id', $formId)->first();
        if ($form === null) {
            abort(404);
        }
        $versions = DB::table('form_versions')->where('form_id', $formId)->orderByDesc('version')->get(['id', 'version', 'schema', 'published_at', 'published_by'])
            ->map(static fn (object $v): array => ['id' => $v->id, 'version' => self::i($v->version), 'schema' => json_decode(self::s($v->schema), true, 32) ?: [], 'published_at' => $v->published_at])->values()->all();

        return Inertia::render('Workflow/FormBuilder', [
            'copy' => __('workflow.ui'),
            'blocks' => __('workflow.forms.block'),
            'usage_labels' => __('workflow.forms.usage'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/FormBuilder'),
            'form' => ['id' => $form->id, 'name' => $form->name, 'active' => (bool) $form->active, 'current_version_id' => $form->current_version_id, 'updated_at' => $form->updated_at],
            'versions' => $versions,
            'schema' => $versions[0]['schema'] ?? ['languages' => ['en', 'am'], 'steps' => [], 'completion' => []],
            'field_types' => DefinitionWriter::TYPES,
            'custom_fields' => DB::table('field_definitions')->where('active', true)->orderBy('entity')->orderBy('position')->get(['id', 'entity', 'key', 'label', 'type'])->all(),
            'usage' => $usage->forForm($formId),
        ]);
    }

    public function createForm(Request $request, FormWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $writer): array {
            $id = $writer->create(['name' => self::s($request->input('name', ''))], $this->user($request)->identifier());

            return ['ok' => true, 'id' => $id];
        });
    }

    public function updateForm(Request $request, string $formId, FormWriter $writer): JsonResponse
    {
        return $this->door(function () use ($request, $formId, $writer): array {
            $changes = [];
            foreach (['name', 'active', 'entity_bindings'] as $key) {
                if ($request->has($key)) {
                    $changes[$key] = $request->input($key);
                }
            }
            $writer->update($formId, $changes, $this->user($request)->identifier());

            return ['ok' => true];
        });
    }

    public function previewForm(Request $request, string $formId, FormPublisher $publisher): JsonResponse
    {
        return $this->door(function () use ($request, $formId, $publisher): array {
            /** @var array<string, mixed> $schema */
            $schema = is_array($request->input('schema')) ? $request->input('schema') : [];

            return ['ok' => true] + $publisher->preview($formId, $schema);
        });
    }

    public function publishForm(Request $request, string $formId, FormPublisher $publisher): JsonResponse
    {
        return $this->door(function () use ($request, $formId, $publisher): array {
            /** @var array<string, mixed> $schema */
            $schema = is_array($request->input('schema')) ? $request->input('schema') : [];

            return ['ok' => true] + $publisher->publish($formId, $schema, $this->user($request)->identifier());
        });
    }

    // ── CSAT ─────────────────────────────────────────────────────────────

    public function csat(Request $request): Response
    {
        return Inertia::render('Workflow/Csat', [
            'copy' => __('workflow.ui'),
            'can' => $this->capabilities->for($this->user($request), 'Workflow/Csat'),
            'settings' => [
                'cooldown_hours' => $this->settings->get('csat.cooldown.hours'),
                'window_hours' => $this->settings->get('csat.window.hours'),
                'comment_prompt_enabled' => $this->settings->get('csat.comment_prompt.enabled'),
            ],
            'labels' => [
                'en' => __('workflow.csat', [], 'en'),
                'am' => __('workflow.csat', [], 'am'),
            ],
        ]);
    }

    public function updateCsat(Request $request): JsonResponse
    {
        return $this->door(function () use ($request): array {
            $user = $this->user($request);
            foreach (['csat.cooldown.hours' => 'cooldown_hours', 'csat.window.hours' => 'window_hours', 'csat.comment_prompt.enabled' => 'comment_prompt_enabled'] as $key => $field) {
                if ($request->has($field)) {
                    $this->settings->set($key, $request->input($field), actorId: $user->identifier());
                }
            }

            return ['ok' => true];
        });
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @param callable(): array<string, mixed> $work */
    private function door(callable $work): JsonResponse
    {
        try {
            return new JsonResponse($work());
        } catch (WebhookDestinationRefused $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage(), errors: ['url' => [\App\Domain\Automation\Webhooks\SsrfRules::CODE]]);
        } catch (InvalidArgumentException|DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return array{name: string, description?: ?string, trigger: string, conditions?: array<string, mixed>, actions?: list<array<string, mixed>>, run_window?: ?array<string, mixed>, schedule_cron?: ?string, cooldown_seconds?: int} */
    private function ruleDefinition(Request $request): array
    {
        /** @var array<string, mixed> $conditions */
        $conditions = is_array($request->input('conditions')) ? $request->input('conditions') : ['all' => []];
        /** @var list<array<string, mixed>> $actions */
        $actions = is_array($request->input('actions')) ? array_values($request->input('actions')) : [];
        $definition = [
            'name' => self::s($request->input('name', '')),
            'description' => is_string($request->input('description')) ? $request->input('description') : null,
            'trigger' => self::s($request->input('trigger', 'ticket.created')),
            'conditions' => $conditions,
            'actions' => $actions,
            'cooldown_seconds' => self::i($request->input('cooldown_seconds', 300)),
        ];
        $cron = $request->input('schedule_cron');
        if (is_string($cron) && $cron !== '') {
            $definition['schedule_cron'] = $cron;
        }
        $window = $request->input('run_window');
        if (is_array($window)) {
            /** @var array<string, mixed> $window */
            $definition['run_window'] = $window;
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    private function policyDefinition(Request $request, bool $partial = false): array
    {
        $definition = [];
        foreach (['name', 'match_conditions', 'targets', 'clock_mode', 'calendar_id', 'warning_pct', 'pause_conditions', 'retarget_mode', 'breach_escalation', 'active', 'position'] as $key) {
            if ($request->has($key)) {
                $definition[$key] = $request->input($key);
            }
        }
        if (! $partial) {
            $definition += ['name' => '', 'targets' => [], 'clock_mode' => 'calendar'];
        }

        return $definition;
    }

    private function hasDraft(string $ruleId, mixed $activeRevisionId): bool
    {
        $latest = DB::table('automation_rule_revisions')->where('rule_id', $ruleId)->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return $latest !== null && $latest !== $activeRevisionId;
    }

    private function tenantTimezone(): string
    {
        $tz = $this->settings->get('tenant.display_timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'Africa/Addis_Ababa';
    }

    /** A guarded string read (request input / DB row property) — never a cast of mixed. */
    private static function s(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);
    }

    /** A guarded int read. */
    private static function i(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /** @return array<string, string> a guarded string→string map (per-language labels) */
    private static function stringMap(mixed $value): array
    {
        $map = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && is_string($item)) {
                    $map[$key] = $item;
                }
            }
        }

        return $map;
    }

    /** @return list<array{type: string, value: mixed}> */
    private static function bundledList(mixed $value): array
    {
        $list = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item) && is_string($item['type'] ?? null)) {
                    $list[] = ['type' => $item['type'], 'value' => $item['value'] ?? null];
                }
            }
        }

        return $list;
    }

    /** @return list<array{value: string, label: array<string, string>}> */
    private static function optionList(mixed $value): array
    {
        $list = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item) && is_string($item['value'] ?? null)) {
                    $list[] = ['value' => $item['value'], 'label' => self::stringMap($item['label'] ?? null)];
                }
            }
        }

        return $list;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r?\n/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $value), static fn (string $v): bool => $v !== ''));
    }
}
