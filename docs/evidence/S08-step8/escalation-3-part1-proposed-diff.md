# ESCALATION #3, PART 1 (S08 step 8) — SearchProxy customers-section deepen

**Status: PREPARED, NOT LANDED. Escalate-first (the #7 shape). Doc 07 files.**
Closing row 3 (BUILD-STATUS S07 step-6 line "deepens with Doc 08") completes when this lands.

## File 1 — `app/Domain/Inbox/Search/SearchProxy.php` (Doc 07)

Constructor gains one dependency:

```php
    public function __construct(
        private readonly SearchIndex $index,
        private readonly ViewHash $hashes,
        private readonly \App\Domain\Customers\Search\CustomerSearch $customerSearch,
    ) {}
```

The v1 customer-section derivation (the `$customers = []; foreach ($tickets['hits'] …` block,
lines 66–82, whose own comment says "customer identity search deepens with Doc 08") is REPLACED by:

```php
        // FR-23's customer section — DEEPENED onto Doc 08's customers_index
        // (T-04; S08 step 8, escalation #3 part 1): real customer documents,
        // the CUSTOMERS-module scope injected by CustomerSearch itself (its
        // own signature — roles are editable, the modules may diverge).
        // FS-01's DB degrade belongs to the DIRECTORY door; the palette keeps
        // Doc 07's uniform unavailable posture, so a degraded answer renders
        // this section empty rather than forking a second outage behavior.
        $customerResult = $this->customerSearch->search($query, $viewer);
        $customers = $customerResult['degraded'] ? [] : array_map(
            static fn (array $hit): array => [
                'id' => $hit['id'],
                'display_name' => $hit['display_name'],
                'username' => $hit['usernames'][0] ?? null,
            ],
            $customerResult['hits'],
        );
```

## File 2 — `resources/js/Components/Inbox/CommandPalette.vue` (Doc 07)

The customers-row type (line 43) and `:key` (line 388) only — the template already renders
exactly `display_name` + `username`; `ticket_ids` is declared but never used in the template:

```ts
    customers: { id: string; display_name: string | null; username?: string | null }[];
```

```html
    :key="customer.id"
```

## Decisions the ruling should cover

1. **Degrade posture (recommended: empty section).** FS-01 names the DIRECTORY search as the
   degrade surface; Doc 07's palette has its own uniform unavailable posture. Recommended: the
   palette's customers section renders EMPTY on a degraded answer (option ii). Alternative (i):
   surface degraded DB hits in the palette — rejected here because it forks a second outage
   behavior inside a Doc 07 surface the document does not ask for.
2. **Permission posture.** v1 derived customer names from ticket documents visible under the
   viewer's ticket scope. The deepened section exposes the same fields (display_name, username)
   under the CUSTOMERS-module scope. Should the section additionally gate on `customers.view`
   (absence for non-holders)? The workspace actor templates all carry customers.view, so the
   observable difference is only for custom-stripped roles. Recommended: yes, gate it — the
   capability-map verdict, rendered as absence; one line in the proxy.
3. **Shape change.** `ticket_ids` is dropped (real customer documents have no ticket grouping);
   `id` is added (click-through to the profile/panel lands with steps 10–11's UI work, the same
   escalation family's part 2). The palette template renders identically today.
4. **Doc 07 test surface.** No existing Doc 07 test asserts the customers-section shape
   (verified: InboxSearchScopeTest asserts tickets/messages/unavailable only). The landing
   commit re-runs the Doc 07 canonical suites and the S07 palette browser spec as the
   no-regression proof.
