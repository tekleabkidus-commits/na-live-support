# Doc 03 — Appendix B: the nine seeded role templates

**Status:** RATIFIED by owner ruling R-10 (2026-08-04, S19 plan approval) — filed in
`docs/ANSWERS-FROM-DOC-AUTHOR.md`. Doc 03 FR-10 cites "Appendix B of the implementation
repo" for the templates' exact grants; no such appendix was ever supplied, so this file IS
it: proposed from the §4.1 catalog under the document's own constraints, ratified verbatim.
`app/Domain/Rbac/RoleTemplates.php` is the machine-readable twin; T-05(c)'s drift test pins
the two together with the seeder's fixtures.

**Constraints carried from the document (each has a test):**

- Super Admin = all 111 permissions, all scopes All (FR-10).
- Read Only = **exactly** the 18 keys whose action segment is `view` and whose risk is
  normal (BR-05 read strictly, ruling R-10): `kb.view_internal` excluded (action is
  `view_internal`), `audit.view` excluded (high-risk never rides a seeded convenience
  template). AC-06 sweeps every mutation route against it.
- `customers.rg_flag.*` appears in NO template except Super Admin (BR-06 — granting RG
  access is always an explicit act).
- Agent-tier templates (Senior Agent, Agent, Junior Agent) and QA/Read Only carry **zero
  high-risk keys** (ruling R-10; the B6-S07 fixture's "2 high-risk" badge on Senior Agent
  is operator-edited content, not template law).
- All nine are `is_system`: editable, never deletable while assigned (FR-02); releases
  never overwrite an edited template (EC-07 — the seeder's fingerprint contract).

**Scope maps** (scoped modules: tickets, conversations, customers, reports):

| Template | tickets | conversations | customers | reports |
|---|---|---|---|---|
| Super Admin | All | All | All | All |
| Admin | All | All | All | All |
| Manager | Dept | Dept | Dept | Dept |
| Team Leader | Dept | Dept | Dept | Dept |
| Senior Agent | Team | Team | Team | Team |
| Agent | Own | Own | Own | Own |
| Junior Agent | Own | Own | Own | Own |
| QA | All | All | All | All |
| Read Only | All | All | All | All |

Team Leader is Department-scoped by US-03's own words ("within my department"); QA is
All-scoped by US-04 ("read all conversations"). Team/Dept resolve to EMPTY sets until
Doc 11 supplies membership sources (T-03 seam, fail-closed).

**Grants per template.** 🛡 = high-risk.

- **Super Admin** — every catalog key (111).
- **Admin** — every key EXCEPT `tickets.delete` 🛡, `customers.delete` 🛡,
  `customers.rg_flag.view` 🛡, `customers.rg_flag.set` 🛡 (107). Hard destruction and RG
  stay explicit acts.
- **Manager** (57) — tickets: all 20 except delete (incl. export 🛡); conversations: view,
  history.full; customers: view, create, edit, notes.add, notes.view, identity.link,
  block; telegram: send, message.delete, bots.view, health.view; webchat: respond,
  widgets.view; sms: send, templates.view, templates.create, templates.edit,
  optout.manage; ai: copilot.use, copilot.insert_draft, decisions.view; kb: view_internal,
  articles.create, articles.edit, articles.publish, categories.manage, feedback.view;
  automation: rules.view, rules.edit, macros.personal.manage, macros.shared.manage,
  forms.view, forms.edit, sla.policies.view; org: teams.manage, queues.manage,
  queues.pull_in, business_hours.edit; admin: users.view, roles.view; reports: view,
  dashboards.create, dashboards.share, export 🛡, schedule, supervisor.live,
  agents.individual 🛡; supervision: monitor 🛡, whisper 🛡, reassign,
  agent_status.force 🛡, broadcast; settings: view.
- **Team Leader** (38) — tickets: the 19 non-high keys; conversations: view, history.full;
  customers: view, create, edit, notes.add, notes.view, block; telegram: send; webchat:
  respond; sms: send, templates.view; ai: copilot.use, copilot.insert_draft,
  decisions.view; kb: view_internal, feedback.view; automation: rules.view,
  macros.personal.manage, macros.shared.manage, forms.view, sla.policies.view; org:
  queues.pull_in; admin: users.view; reports: view, dashboards.create, supervisor.live;
  supervision: monitor 🛡, reassign, broadcast.
- **Senior Agent** (36) — tickets: view, create, edit, reply, note, assign, assign_self,
  status.change, priority.change, close, reopen, merge, split, escalate, follow,
  tags.manage, fields.edit, spam.mark; conversations: view, history.full; customers:
  view, create, edit, notes.add, notes.view, identity.link; telegram: send; webchat:
  respond; sms: templates.view; ai: copilot.use, copilot.insert_draft; kb: view_internal,
  articles.create, articles.edit, feedback.view; automation: macros.personal.manage,
  forms.view; org: queues.pull_in; reports: view. Zero high-risk.
- **Agent** (24) — tickets: view, create, edit, reply, note, assign_self, status.change,
  close, reopen, escalate, follow, fields.edit, spam.mark; conversations: view;
  customers: view, create, notes.add, notes.view; telegram: send; webchat: respond; ai:
  copilot.use, copilot.insert_draft; kb: view_internal; automation:
  macros.personal.manage, forms.view. Zero high-risk. *(Count includes both automation
  keys — 25 total.)*
- **Junior Agent** (14) — tickets: view, create, reply, note, assign_self, follow;
  conversations: view; customers: view, notes.add, notes.view; telegram: send; webchat:
  respond; ai: copilot.use; kb: view_internal; automation: macros.personal.manage.
  Zero high-risk. *(Count: 15.)*
- **QA** (9) — tickets.view, conversations.view, conversations.history.full,
  customers.view, customers.notes.view, ai.decisions.view, kb.view_internal,
  kb.feedback.view, reports.view. Read-and-score; every reply/edit control absent
  (US-04, proven in AC-08's sample).
- **Read Only** (18) — tickets.view, conversations.view, customers.view,
  customers.notes.view, telegram.bots.view, telegram.health.view, webchat.widgets.view,
  sms.templates.view, sms.gateways.view, ai.decisions.view, kb.feedback.view,
  automation.rules.view, forms.view, sla.policies.view, admin.users.view,
  admin.roles.view, reports.view, settings.view.

`RoleTemplates::all()` is authoritative for the exact machine-readable sets; the counts
above are descriptive (the registry-is-normative rule applies to this appendix too). The
seeder test pins Super Admin at 111, Read Only at the literal 18, and zero-high on the
agent tier + QA + Read Only.

---

## Amendment — R-26 (S07 step 4, 2026-08-10, owner-signed; under the R-10 ratification)

`views.manage` (the third addition of record; interpretation A-34) seeds into
ALL templates EXCEPT Read Only: Super Admin and Admin receive it by their
computed rules; Manager, Team Leader, Senior Agent, Agent, Junior Agent and
QA gain it explicitly (personal saved views are workspace configuration, not
data writes). Read Only's exclusion is automatic — its grant list is computed
as non-high `.view`-suffix keys, which `views.manage` fails — so BR-05's
zero-accepted-writes bar stays physics. The zero-high-risk constraints are
unaffected (normal risk). `views.share` remains seeded into NO template.

---

## Amendment — R-D10-1 (S10 step 0.4, 2026-08-27, owner-signed; amended at the S10 plan-approval rulings)

`ai.providers.manage` (the fourth addition of record; Doc 09 FR-02's change-log
key) registers at risk HIGH ("ai.thresholds.edit-adjacent" — FR-02's own words; a
provider/model switch is a high-visibility audit event). Catalog 113→114, high
38→39. Seeding: Super Admin (all-keys rule) and Admin (computed: everything minus
the four hard-destruction/RG keys) receive it; every other template excludes it —
the agent tier, QA and Read Only carry zero high-risk keys by the R-10 constraint,
which the pinned test enforces. The switch DOOR (`POST admin/ai/providers/routing`)
binds to it; the B8-S08 provider screen lands in the [B8] fidelity pass.
