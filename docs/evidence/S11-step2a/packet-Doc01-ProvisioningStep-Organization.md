# S11 STOP-C.1 PACKET — Doc 01 core edit: `ProvisioningStep::Organization` (PROPOSED; countersign BEFORE landing)

Ruling of record: C.1 APPROVED (2026-08-30) — the default department exists before Active; the
RoleSeeder pattern; escalate-first with countersign.

## `app/Domain/Foundation/Provisioning/ProvisioningStep.php`
```diff
     /** SEAM: Doc 03 seeds the default role set. */
     case DefaultRoles = 'default_roles';
 
+    /** Doc 11 FR-01: the seeded department set + one default queue each (OrganizationSeeder). */
+    case Organization = 'organization';
+
     /** SEAM: Doc 12 owns SLA policies. */
     case SlaPolicy = 'sla_policy';
@@ ordered()
             self::DefaultRoles,
+            self::Organization,     // after roles (templates exist), before SLA/hours (Doc 12 may reference departments)
             self::SlaPolicy,
@@ isSeamAwaitingItsModule()
-            self::DefaultRoles, self::SlaPolicy, self::BusinessHours, self::KnowledgeBase => true,
-            self::TenantRecord, self::DefaultSettings => false,
+            self::SlaPolicy, self::BusinessHours => true,
+            self::TenantRecord, self::DefaultSettings, self::DefaultRoles, self::Organization, self::KnowledgeBase => false,
```
(NOTE for the countersign: `DefaultRoles` and `KnowledgeBase` are FILLED seams today (Doc 03 S19,
Doc 10 S10) yet still report `isSeamAwaitingItsModule() = true` — a pre-existing staleness this
packet corrects in passing; flagged, not slipped. If the advisor prefers a separate line, it splits.)
@@ owningDoc()
+            self::Organization => 'Doc11',
```

## `app/Domain/Foundation/Provisioning/TenantProvisioner.php` — ONE dispatch line
```diff
             ProvisioningStep::DefaultRoles => app(\App\Domain\Rbac\RoleSeeder::class)->seed(),
+            // Doc 11 (S11) filled its step: the FR-01 seeded department set, each with one
+            // default queue (EC-10's defaultless-department net holds from birth); RG
+            // department with the is_rg queue; idempotent under this class's retry semantics
+            // (seeded_fingerprint — an operator-edited seed is never overwritten, BR-04).
+            ProvisioningStep::Organization => app(\App\Domain\Org\Seeding\OrganizationSeeder::class)->seed(),
```

## i18n
`lang/en|am/foundation.php` gain `provisioning.step.organization` (§8's progress screen label; N-03).

## Proofs
- Doc 01's provisioning suites — the pins found by grep: `tests/Isolation/ConcurrentProvisioningTest.php`
  (the step list) and `tests/Feature/Foundation/I18nFallbackTest.php` (the full-key sweep that will
  demand `foundation.provisioning.step.organization` in en AND am) — both move WITH this change.
- NEW `tests/Feature/Org/OrganizationSeederTest.php`: the nine FR-01 departments + default queues
  exist and the tenant is Active only after; re-run is a no-op; an edited seed survives (fingerprint);
  `is_rg` queue exactly one; `ReopenConsumerDrillTest`'s `tenant.provisioned` pin UNCHANGED
  (no consumer added — STOP-C.1 is the step, not a consumer).
