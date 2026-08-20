# STEP 8 MANDATORY STOP — two preconditions (S09 batch C)

**Status: PROPOSED — awaiting advisor countersign. Nothing of step 8 lands before it
returns.** Both items are step-8 preconditions discovered at planning depth, bundled
into one stop so the batch loses one round trip, not two.

## ITEM 1 — CORE-EDIT ESCALATION: `app/Support/Quota/QuotaMeter.php` gains a READ-ONLY `headroom()`

**Owner:** Doc 01 (Foundation). **Why:** Doc 09 EC-12/NFR-06 — "quota exhausted
mid-conversation → the chatbot completes the CURRENT turn from reserve headroom (5%
soft buffer), then straight-to-human; admins alerted at 80% long before." Enforcing a
5% reserve PRE-CALL needs to READ usage vs limit. QuotaMeter's only public surface is
`allow()` (increment-then-refuse at 100%). The alternatives are both banned: a second
implementation of the counter's key naming (the no-second-implementation law — the
key format `quota:{value}:{bucket}` is private), or abusing `allow(0)` (no reserve
visibility). The honest shape is one read-only method on the owner's class.

```diff
--- a/app/Support/Quota/QuotaMeter.php
+++ b/app/Support/Quota/QuotaMeter.php
@@ (after allow(); no existing line changes)
+    /**
+     * READ-ONLY headroom for the current window (RULED CORE EDIT, S09 step 8 —
+     * Doc 09 EC-12/NFR-06's 5% reserve semantics): {used, limit} in the quota's
+     * own units. NEVER increments, NEVER refuses — the gate that refuses stays
+     * allow(), SEC-05's pre-call posture unchanged. Cumulative quotas answer
+     * from the ledger exactly as allowCumulative() reads it. A metering
+     * outage PROPAGATES (the AI gate treats "unknown headroom" as reserve
+     * mode — refuse new turns, never bill blind); allow()'s per-surface
+     * degrade policy is untouched because this method is not a gate.
+     *
+     * @return array{used: int, limit: int}
+     */
+    public function headroom(Quota $quota): array
+    {
+        $limit = $this->limit($quota);
+        $window = $quota->windowSeconds();
+
+        if ($window === null) {
+            $used = (int) DB::table('tenant_quota_usage')
+                ->where('tenant_id', $this->context->tenantId())
+                ->where('quota', $quota->value)
+                ->sum('used');
+
+            return ['used' => $used, 'limit' => $limit * $quota->limitUnitMultiplier()];
+        }
+
+        $bucket = intdiv(time(), $window) * $window;
+        $counted = $this->cache->get(sprintf('quota:%s:%d', $quota->value, $bucket));
+
+        return [
+            'used' => is_int($counted) ? $counted : (is_numeric($counted) ? (int) $counted : 0),
+            'limit' => $limit,
+        ];
+    }
```

**Proving obligations if countersigned:** (a) Doc 01's quota suites unchanged and
green on the edited file (the regression floor); (b) a new assertion in Doc 09's
QuotaEnforcementTest that `headroom()` mirrors `allow()`'s own counter to the unit
(allow(n) then headroom().used === n in the same window); (c) the EC-12 reserve
fixture: at ≥95% the gate lets the CURRENT turn complete and routes the NEXT to a
human (`quota_exhausted` handoff), at 100% `allow()` still refuses — sabotage #12
(check moved post-call) goes red on exactly that fixture.

**Consumption, not fork, everywhere else:** T-07's metering accumulates ACTUAL
provider token counts post-call through `allow(actual)` (the same counter the FS-05
`quota:reconcile` persists — consumed), catching `QuotaExceeded` post-call (the work
is done; the next pre-call check refuses). 80% alert = QuotaMeter's own
`quota.warning_80` (already built).

## ITEM 2 — ENUMERATION AMENDMENT (standing rule 16; the A-4/A-15 mechanism): event family +1

**Finding (flagged at plan approval, now due):** T-01 ("all-tripped → degraded mode
events") and FS-01 ("the whole sequence is evented and visible in health") require
events that FR-21's closed list of six does not name. Reusing `channel.health.changed`
would overload a Doc 04/05 channel schema with provider semantics (the A-15 reasoning
against overloading).

**Filed:** `ai.provider.health_changed` — schema_version 1, **tenant-scoped by
construction** (the outbox is tenant-scoped; the breaker is platform-scope, so the
event fires per tenant when THAT tenant's chain state transitions), **transition-only,
tri-state** (the A-22 shape): payload `{tenant_id, provider, status}` with
`status ∈ healthy|degraded|down` (`degraded` = a provider tripped, the chain still has
a live hop; `down` = all exhausted → bypass-to-handoff). Producers: Doc09/Orchestrator.
Consumers: Doc15/Reporting, Doc17/Audit (the health-banner consumption in Doc 07's
indicator is a foreseen Doc 07 escalation, NOT part of this amendment — v1 is emitted
+ logged + reportable). The event-family count becomes **seven** (FR-21's six + this
filed one); the plan's §1 enumeration line is amended by this filing.

## ITEM 3 (information, no ruling needed) — step-7 interpretation carried to checkpoint C

T-08's literal "handoff packet embeds decision_id": the Doc 06 `HandoffPacket` contract
(A-7's field list, schema_version 1, immutable rows) has no such field. The join is
realized from the ai_decisions side (the override's own decision row carries
`ticket_id`; enrichment consumers join ticket events → decision). Doc 06's contract is
untouched. If the advisor wants the literal embedding, it is a Doc 06 `schema_version
2` escalation — not assumed.
