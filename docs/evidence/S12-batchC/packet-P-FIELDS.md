# PACKET P-FIELDS (S12 step 8; escalate-first — PROPOSED, countersign before landing)

FINDING (verified in code at step 8): Doc 08's CustomerFieldValues::definitions()
reads `fixtures/customers/field-definitions.json` — the [D12] seam Doc 08's own
FR-16 row documents ("the defs are fixture-fed here"). Doc 12's
field_definitions table (entity=customer) now exists; until the source swaps,
Doc 08's door refuses values for any NEW customer field ("unknown customer
field") — Doc 12's ValueWriter validates against the REAL definitions and then
hits that wall.

THE EDIT (Doc 08's ONE file, the A-53/HoursContract swap shape):
CustomerFieldValues::definitions() reads field_definitions (entity=customer)
where rows exist — {key, label_i18n (from label jsonb), type, retired: !active}
— with the FIXTURE as the fallback when the tenant has no customer rows (the
PRE state, kept as the control; the seeded betting trio arrives as REAL rows
at provisioning in the same commit, so production gains nothing fictional).
CONTROLS: (1) PRE — with no customer field_definitions rows, Doc 08's suites
run byte-untouched on the fixture (BettingFieldsTest, DuplicateHintScopeTest,
RgStorageTest's field legs); (2) POST — a new customer definition accepts a
value end-to-end through BOTH doors, and a DEACTIVATED real row refuses as
FieldRetired (EC-09's law from the REAL source); (3) the duplicate-player_id
hint (SEC-05) unchanged on the trio. Doc 08's suites green in the same commit.

---
COUNTERSIGN GRANTED AS FILED (batch-C acceptance, grounded at b0365de: A-53 swap
shape; all three controls incl. EC-09 from the real source; seeded trio as real
rows at provisioning same commit; Doc 08 suites green same commit). LANDED at
S12 step 13 (this commit). Doc 08's ONE file: `CustomerFieldValues::definitions()`
reads `field_definitions` (entity=customer, position order) for a BOUND tenant
holding rows — {key, label_i18n, type, retired: !active} — and the [D12]
fixture only when it holds none (or no tenant is bound: the platform context).
label_i18n keeps its KEY contract where a platform key exists (the trio); an
operator-authored definition carries its own label text (operator content),
rendered as-is by the card's t() — Doc 08's card learning the label map is a
named [B12-batch]-adjacent follow-up outside this packet's one file. The trio
arrives as REAL rows at provisioning: `CustomerFieldSeeder` (Doc 12) via the
DURABLE `CustomerFieldSeederConsumer` on tenant.provisioned (the KB seeder
shape; catalog consumers + doc12.json read-slice + the two registry pins moved
same commit); the fixture's retired loyalty_tier is deliberately NOT seeded.
CONTROLS (tests/Feature/Fields/CustomerDefinitionsSourceTest): (1) PRE — no
rows → the fixture byte-for-byte, and Doc 08's own suites ran on it
untouched; (2) POST — a NEW definition accepts a value through BOTH doors and
a DEACTIVATED real row refuses FieldRetired at Doc 08's door / "retired" at
Doc 12's, the fact surviving read-only; (3) the SEC-05 duplicate-player_id
hint unchanged on the real trio (count + scope links, no names). Doc 12's own
step-8 pre-state pin (ValueStorageTest: "unknown customer field") FLIPPED on
record. PROOF: Doc 08 suites + Doc 12 fields suites + the seeder siblings +
the pins: 155 passed / 1160 assertions exit 0 (pfields-suites-03.txt; -01 =
my duplicated-path invocation refused by Pest, -02 = three honest reds: my
links-shape guess, the flipping pin, and the unbound-tenant read — all mine,
fixed at cause); lint: -01 RED (five PHPStan-max catches at my DB-row typing in the swap — fixed at cause with the house list<object{}> shape; the first commit message claimed exit 0 from the wrong file and was AMENDED before push), -02 exit 0; gates -01 and -02 14/14 exit 0.
