# S11 step 3 — PACKET 3 (PROPOSED; countersign BEFORE landing): the Doc 03 membership BINDING + the A-50 point-3 id source

Ruling of record: plan §11 row 6 (Doc 03 core edit), STOP-D A-50 countersigned with five obligations,
plan step 1(ii)'s transition pair.

## The finding that shapes the diff
Every built consumer of the Team level compares its ids against a DEPARTMENT column:
`TicketScope::columns()` / `ListReadModel::columns()` map `team → department_id` (the S06 placeholder);
`ViewVariants` L119-122 tests `team:`/`dept:` ids against `department_id`; `CustomerScope` L10-16/46-54
filters `department_ids` for both (its own comment: "team:/dept: — department membership"). Only Doc 03's
`ScopeFixtureTest` fixture table has a real `team_id` column. So the interface's `teamIdsFor()` (team ids,
Doc 03 FR-03's literal) is the wrong id KIND for tickets/customers, and A-50 point 3 (the team's queue reach,
as department ids) is what those consumers were built to receive. The packet makes that explicit instead
of overloading `teamIdsFor()`.

## Diff (Doc 03 files unless marked)
```diff
 app/Support/Rbac/Scopes/MembershipSource.php
   public function teamIdsFor(User $user): array;
   public function departmentIdsFor(User $user): array;
+  /** A-50 point 3: departments of the queues the user's teams are members of — the
+   *  "records of the user's teams" for aggregates that carry department_id, not team_id. */
+  public function teamReachDepartmentIdsFor(User $user): array;
 app/Support/Rbac/Scopes/EmptyMembership.php
+  public function teamReachDepartmentIdsFor(User $user): array { return []; }   // still fail-closed
 app/Support/Rbac/Scopes/ScopeColumns.php
-  public function __construct(public string $owner, public string $team, public string $department) {}
+  /** @param 'team'|'department' $teamColumnHolds what the `team` column contains — team ids (Doc 03's
+   *  fixture table) or department ids (tickets, list items: the team's queue reach, A-50 point 3). */
+  public function __construct(public string $owner, public string $team, public string $department,
+                              public string $teamColumnHolds = 'team') {}
 app/Support/Rbac/Scopes/ScopeSets.php  apply():
-  'Team' => $this->membershipConstraint($query, $columns->team, $this->membership->teamIdsFor($user)),
+  'Team' => $this->membershipConstraint($query, $columns->team, $columns->teamColumnHolds === 'department'
+      ? $this->membership->teamReachDepartmentIdsFor($user)
+      : $this->membership->teamIdsFor($user)),
 app/Domain/Tickets/Storage/TicketScope.php (Doc 06) + app/Domain/Inbox/ReadModels/ListReadModel.php (Doc 07):
-  new ScopeColumns(owner: 'assignee_id', team: 'department_id', department: 'department_id')
+  new ScopeColumns(owner: 'assignee_id', team: 'department_id', department: 'department_id', teamColumnHolds: 'department')
   (+ the S06 "PLACEHOLDER … no team column exists yet" docblock rewritten to A-50 point 3 — the column
    stays department_id BY DESIGN now, not as a placeholder)
 app/Domain/Inbox/Realtime/ViewHash.php (Doc 07) + app/Domain/Customers/Search/CustomerScope.php (Doc 08):
-  'Team' => 'team:'.implode(',', $this->sorted($this->membership->teamIdsFor($user))),
+  'Team' => 'team:'.implode(',', $this->sorted($this->membership->teamReachDepartmentIdsFor($user))),
 app/Providers/AppServiceProvider.php — THE BINDING:
-  MembershipSource::class → EmptyMembership::class
+  MembershipSource::class → \App\Domain\Org\Membership\OrgMembership::class
   (comment rewritten: "Doc 11 (S11 step 3) bound the real sources — the transition proven by
    MembershipTransitionTest; EmptyMembership remains the test-only fail-closed control")
 app/Console/Commands/RbacExpireOverrides.php  tidyBoundTenant():
+  // Doc 11 T-07/EC-07: the temporary queue/department rows linked to this override END with it
+  // (ended_at, never deleted — FLAG C); an already-ended row is a no-op.
+  DB::table('queue_members')->where('override_id', $override->id)->whereNull('ended_at')->update(['ended_at' => DB::raw('clock_timestamp()')]);
+  DB::table('user_departments')->where('override_id', $override->id)->whereNull('ended_at')->update([...same...]);
+  $this->events->publish('queue.temporary_grant.expired', [...cause => 'expired']);   // per queue row
```
Doc 11 side (no countersign needed): `app/Domain/Org/Membership/OrgMembership.php` (written; DB-time expiry
check in `departmentIdsFor`, tombstones excluded, sorted ids).

## Test pins that FLIP with the binding (each a deliberate move inside this packet)
- `tests/Feature/Rbac/ScopeFixtureTest.php` — the AC-03 suite: its `FixtureMembership` cases stay (the
  contract), PLUS a new block re-proving the SAME exact sets against `OrgMembership` with real rows in
  `teams/team_members/user_departments` (obligation 5), with `teamColumnHolds` default 'team' for the
  fixture table. Its "production binds EmptyMembership → Team/Dept see nothing" test (L222-233) becomes
  "the test-only `EmptyMembership` still fails closed" (rebound explicitly) — the production assertion moves
  to `MembershipTransitionTest`.
- `tests/Feature/Tickets/ChannelScopeReproofTest.php` L28-36 pin ("Team/Dept must STILL be refused… until Doc
  11") and `TransferEscalateTest` L80/L94-101 ("fails closed pre-Doc 11") — the pre-Doc 11 half of each is
  INVERTED into the post-binding truth (a Team-scoped agent WITH reach is admitted; one WITHOUT stays refused),
  the S20 `LaneOrderingForcedBackstepTest` inversion precedent.
- `tests/Security/InboxSecurityTest.php:56-60` and `PerViewerCountsTest.php:104` — UNTOUCHED (STOP-E: the
  Own pin stands byte-identical).

## Proofs riding the packet
1. `tests/Security/MembershipTransitionTest.php` (step 1(ii)'s pair): PRE — `EmptyMembership` bound
   explicitly with the real tables POPULATED → Team/Dept/team-reach all `[]`; POST — `OrgMembership` →
   EXACTLY the expected sets (direct ∪ team ∪ unexpired temporary ∩ department access), a foreign tenant's
   row and an expired temporary row never appear (named lines).
2. A-50 obligations: (1) INDEPENDENCE — dept-row-without-queue vs queue-without-dept-row, FR-04's config
   error on the second (badge data) — `EffectiveMembersTest`; (2) DIVERGENCE — a teammate ASSIGNED a ticket
   in a department outside the team's reach is NOT in Team scope — `MembershipTransitionTest`; (3) ONE-ACT
   AUDIT — `org.department.access_granted` writes both rows in one transaction (step 3's `DepartmentAccess`
   door) — `DepartmentAccessTest`; (4) JOINT EXPIRY — three rows on one override_id; sweep disabled → the
   assignment-time check refuses — step 7 (`TemporaryMembershipTest`), the department half asserted there
   too; (5) AC-03 re-proof — `ScopeFixtureTest`'s new block.
3. Owning-document suites green: Doc 03 (`tests/Feature/Rbac`), Doc 04 (`ChannelScopeReproofTest`), Doc 06
   (`tests/Feature/Tickets`), Doc 07 (`PerViewerCountsTest`, `ViewChannelAuthTest`, `InboxSecurityTest`,
   `SyncGoldenTest`), Doc 08 (`CustomerSearchScopeTest`).
4. Sabotage #1 (scope-widening: `departmentIdsFor` poisoned with a foreign id → red at the named line).

## [D11] closing rows this packet flips at close
Doc 03 FR-03 / T-03 / AC-03 (+ top-table row 11 annotation), Doc 06 FR-22 annotation, Doc 03 FR-09
annotation (pull-in grants real at step 7).
