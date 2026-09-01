<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Rules\SystemActorFloor;

/**
 * Doc 12 FR-03 / BR-02 / SEC-01 — the CLOSED action-type registry. The 14 v1
 * types are this list; growth is a filed amendment (§19's catalog-extension
 * path), never a silent case. Every arm:
 *   - is bounded by the ACTIVATOR-CAPABILITY FLOOR before it runs (SEC-01):
 *     the revision's captured floor must allow the action type, or the outcome
 *     is `floor_refused` — honestly logged, chain continues;
 *   - acts THROUGH ITS OWNING DOOR (BR-02: status via TicketTransitionService,
 *     placement via Doc 11's doors, SMS template-only) — the
 *     AutomationNoBypassTest sweep is the physics over this directory;
 *   - returns its honest outcome {action, outcome, reason?, superseded_state?}.
 *
 * S12 step 4 ships the REGISTRY + floor gate with ZERO arms bound: every type
 * resolves to `unbuilt` and logs `failed/unbuilt_action` honestly (no fake
 * success exists to ship). The arms land at steps 5 and 13; binding one is a
 * container registration, not an edit here.
 */
final class ActionRegistry
{
    public const TYPES = [
        'assign', 'transfer_department', 'set_priority', 'set_status',
        'add_tags', 'remove_tags', 'set_custom_field', 'send_macro_reply',
        'add_note', 'notify', 'send_sms_template', 'webhook_out',
        'run_ai_task', 'escalate', 'suppress',
    ];

    /** @var array<string, AutomationAction> */
    private array $arms = [];

    public function __construct(private readonly SystemActorFloor $floor) {}

    public function bind(string $type, AutomationAction $arm): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('unknown automation action type "%s" — the registry is closed (FR-03)', $type));
        }
        $this->arms[$type] = $arm;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $basis  EC-01: the facts the rule matched on
     * @return array<string, mixed> the honest outcome
     */
    public function execute(string $type, array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return ['action' => $type, 'outcome' => 'failed', 'reason' => 'unknown_action'];
        }
        if (! $this->floor->allowsExecution($executionId, $type)) {
            // SEC-01: the activator's captured floor does not cover this type —
            // a demoted author's old rule cannot out-privilege the org.
            return ['action' => $type, 'outcome' => 'failed', 'reason' => 'floor_refused'];
        }
        $arm = $this->arms[$type] ?? null;
        if ($arm === null) {
            return ['action' => $type, 'outcome' => 'failed', 'reason' => 'unbuilt_action'];
        }

        return $arm->run($action, $executionId, $automationDepth, $basis);
    }
}
