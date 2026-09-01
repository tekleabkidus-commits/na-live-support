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
