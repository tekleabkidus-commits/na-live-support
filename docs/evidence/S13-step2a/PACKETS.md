# SESSION-13 — CORE-EDIT PACKETS proposed at step 2a (escalate → countersign → land; each with a fail-first control; ZERO landed-then-escalated)

## P-WIDGET — Doc 02 `app/Models/Widget.php`: the fillable growth SCHEMA AMENDMENT 7 item 1 needs
**Edit (additive):** `#[Fillable(['key_id', 'allowed_origins'])]` → `#[Fillable(['key_id', 'allowed_origins', 'name', 'live_version_id', 'bundle_channel', 'test_mode', 'identity_secret_ref', 'identity_secret_previous_ref', 'identity_secret_rotated_at'])]`; `casts()` gains `'test_mode' => 'bool'`, `'identity_secret_rotated_at' => 'immutable_datetime'`; one relation `liveVersion(): BelongsTo`. The docblock's own sentence ("Doc 13 adds its config columns here expand-contract") is the sanction; nothing Doc 02 reads changes (`allowed_origins` untouched). **Fail-first control:** `WidgetDesignerTest` writes `name` through the model BEFORE the packet lands → `MassAssignmentException` RED, captured; after → green. Doc 02's `WidgetSessionTest`/`WidgetTokenTheftTest`/`WidgetPartitionTest` green unmodified in the same commit.

## P-N14 — Doc 06 `app/Domain/Tickets/Metrics/MetricExclusion.php`: N-14's second half (as ruled, STOP-H)
**Edit (additive, both forms from one truth):** the class gains the test-traffic half its docblock reserved ("it enters this predicate when Doc 15 defines its carrier") — the carrier is `widgets.test_mode`, reached from a ticket through `conversations.channel_ref_id` (the widget id) when `conversations.channel = 'webchat'` — expressed as DATA, never a channel literal in a conditional (N-20): `excluded()` also answers true when the ticket's conversation's `channel_ref_id` names a widget with `test_mode = true` (one query, `widgets` joined by id — a non-webchat conversation's `channel_ref_id` matches no widget row, so the predicate is channel-blind by construction); `includedSql($column, $conversationColumn = 'conversation_id')` appends `AND NOT EXISTS (SELECT 1 FROM conversations c JOIN widgets w ON w.id::text = c.channel_ref_id WHERE c.id = <conversationColumn> AND w.test_mode)`. The docblock's "Recorded, not invented" paragraph is replaced by the carrier's statement. **Fail-first control:** `MetricExclusionTest` (new, Doc 13-owned, beside Doc 06's existing tests): a ticket whose conversation belongs to a `test_mode` widget appears in `includedSql`'s result set BEFORE the packet → RED captured; after → excluded by BOTH forms (row form + SQL form agree — the four-surface parity the class promises); the positive control: a `test_mode=false` widget's ticket stays INCLUDED; a Telegram ticket unaffected. Doc 06's `MetricExclusion` golden fixtures (AC-06) green unmodified same commit. `WidgetHealthTest` applies the SAME predicate to Health reads (a test-mode widget's counters excluded from every aggregate feed; its own Health tab shows them under the "Test" badge — BR-07 says sandbox traffic never pollutes REPORTS, not that the operator cannot see their own test widget).

## P-MEDIA-URL — Doc 04 `config/filesystems.php` (`media` disk) + `.env.example`: the media origin as its OWN host (STOP-I as ruled)
**Edit (one line + one env line):** the `media` disk gains `'temporary_url' => env('AWS_TEMPORARY_URL')` (Laravel's AwsS3V3Adapter rebases presigned URLs onto this base when set; empty/absent = unchanged behaviour — production's value is Doc 18's media domain, [D18]); `.env.example` documents it beside `AWS_ENDPOINT`; the dev `.env` (owner's, uncommitted) sets `AWS_TEMPORARY_URL=https://media.harness.localhost:8443/na-live-support` and the HARNESS media site proxies to `minio:9000` with `Host: minio:9000` restored so SigV4 validates. **Fail-first control:** `WidgetMediaOriginTest` asserts BEFORE the packet that `MediaStore::signedUrl` mints a URL on `minio:9000` (unreachable from a browser) → the assertion "resolves on the MEDIA host" RED; after → the URL's host is the media host, signed (`X-Amz-Signature` present), TTL'd (`X-Amz-Expires` = 600), and a curl of it through the HARNESS answers 200 with **NO `Set-Cookie`** (the cookie-less physics as ruled) — plus the negative: a tampered signature → 403 from MinIO. Doc 04's `MediaStoreTest`/`ArticleMediaTest` green unmodified same commit.

## P-SEED — Doc 12 `app/Domain/Forms/Seeding/FormSeeder.php`: two entries in `forms()` (seed data only; definitions/doors unchanged, as ruled)
**Edit (data only):** `forms()` gains `'Pre-chat' => ['department' => 'general', 'schema' => {languages [en, am]; ONE step `identity` {name text required, phone phone required} (the drawn law: two fields, never more); completion tags ['pre-chat']}]` and `'Offline message' => ['department' => 'general', 'schema' => {languages [en, am]; ONE step `offline` {name text required, phone phone required, story rich_text required "What's happened" / "ምን ተከሰተ"}; completion tags ['offline']}]` — byte-for-byte the shapes `FormPreChatContractTest` already binds (its `preChatSchema()`/offline fixture), so the contract test's fixture and the seeded rows cannot drift; presence-by-name idempotency unchanged (an operator's rename hides their copy from re-seeds, as the seeder's docblock says). The Designer's form pickers list every published form; these two are the defaults the Content tab offers. **Fail-first control:** `WidgetFormsSeedTest` asserts the two names exist after provisioning → RED before; after → present, published (one immutable version each), the pre-chat payload ≤ 30 KB (NFR-04's budget re-asserted on the new forms). `FormPreChatContractTest`, `FormPublishTest`, `SeedingTest` green unmodified same commit. Amharic strings are machine-authored → the R-14 queue, non-destructive tier (labels).

## P-FRAME-ANCESTORS — Doc 02 `app/Http/Middleware/SecurityHeaders.php`: the `frame-ancestors` arm its own comment reserves (proposed at step 7; countersign before landing)
**Why a request attribute, not the `$surface` parameter:** `SecurityHeaders` is APPENDED to the `web` and `api` groups (`bootstrap/app.php:131-132`) with the default `staff` surface, so it wraps every response — a route-level `SecurityHeaders::class.':widget'` would run a SECOND instance and double-stamp (the file's own warning). Group middleware runs OUTSIDE route middleware, so a Doc 13 route middleware on the panel document (`ResolveWidgetFrame`, runs AFTER `tenant:widget_key`) can set a request attribute during the request phase that the group instance reads on its response side.
**Edit (additive, three lines):** a public const `REQUEST_FRAME_ANCESTORS_ATTRIBUTE = 'frame_ancestors'`; in `handle()`, `$ancestors = $request->attributes->get(self::REQUEST_FRAME_ANCESTORS_ATTRIBUTE);` and the `match` becomes: a list attribute present → `frame-ancestors <the origins joined by space>` (Doc 13 supplies the resolved widget's `allowed_origins` through Doc 02's OWN `OriginAllowlist` normalisation — the validation stays Doc 02's; `*.brand.com` renders as `https://*.brand.com`, which is CSP's own wildcard grammar and matches the dot boundary exactly as EC-12 draws) and NO `X-Frame-Options: DENY` on that response (the header cannot express a list; CSP is the braces); attribute absent → byte-identical to today (`'none'` + DENY on staff). The `default => "'none'"` arm stays for every other surface. **Fail-first control:** `SecurityHeadersTest` gains the widget-surface case S14 named as missing: BEFORE the packet the panel document answers `frame-ancestors 'none'` + `X-Frame-Options: DENY` → the HARNESS host page's iframe is REFUSED by Chromium (captured: the panel never mounts) → RED; after → `frame-ancestors https://customer.harness.localhost:8443` exactly, no `X-Frame-Options`, the iframe mounts; the negative: a page on the PLATFORM origin (not allowlisted) embedding the panel is refused by the browser (the CSP layer of AC-03 — Doc 02 T-07/EC-08's structural half, R-D13-4 rows 6/7). Every existing `SecurityHeadersTest` case green unmodified (staff surface byte-identical), same commit.

## ATTACHED PROPOSED FILES (land only after countersign)

### database/migrations/2026_09_04_000100_schema_amendment_7_widgets.php (proposed)
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 7 (Doc 13, countersigned at S13 step 2a): the widget's own
 * tables + the expand-contract growth of Doc 02's `widgets` (its docblock's own
 * sanction; the model growth is packet P-WIDGET).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   widgets                  (Doc 02's grants stand) INSERT/UPDATE grow by EXACTLY the
 *                            new business columns + updated_at; still NO DELETE.
 *   widget_config_versions   INSERT-only — NO UPDATE, NO DELETE (FR-20: an immutable
 *                            publish; rollback = a NEW row + the widgets pointer flip);
 *                            published_at app-DENIED (history ordering, DB time).
 *   widget_visitor_contexts  INSERT + UPDATE (business columns + updated_at); NO DELETE
 *                            (erasure is Doc 17's); captured_at app-DENIED, re-stamped
 *                            by a fill trigger on UPDATE (R-20: DB time orders captures).
 *   widget_health_counters   INSERT (initial values) + UPDATE (counters, blocked_origins,
 *                            channel_status*, updated_at) — increments are SET c = c + 1
 *                            inside the UPDATE (monotone by construction); NO DELETE.
 *   widget_beacon_samples    INSERT-only; occurred_at app-DENIED; NO UPDATE, NO DELETE.
 *   ref_widget_bundles       platform reference data (no tenant_id, no RLS): app SELECT
 *                            only; the platform_admin role writes it (release pipeline /
 *                            the local console commands that prove FR-04's pointer).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        // ── 1. widgets: expand-contract growth (Doc 02 table) ─────────────────
        DB::statement(<<<'SQL'
            ALTER TABLE widgets
                ADD COLUMN name                          text           NOT NULL DEFAULT '',
                ADD COLUMN live_version_id               uuid,
                ADD COLUMN bundle_channel                text           NOT NULL DEFAULT 'stable',
                ADD COLUMN test_mode                     boolean        NOT NULL DEFAULT false,
                ADD COLUMN identity_secret_ref           text,
                ADD COLUMN identity_secret_previous_ref  text,
                ADD COLUMN identity_secret_rotated_at    timestamptz(6),
                ADD COLUMN deleted_at                    timestamptz(6),
                ADD CONSTRAINT widgets_bundle_channel_check CHECK (bundle_channel IN ('stable', 'early'))
        SQL);

        // ── 2. widget_config_versions ─────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_config_versions (
                id           uuid           NOT NULL PRIMARY KEY,
                tenant_id    uuid           NOT NULL REFERENCES tenants (id),
                widget_id    uuid           NOT NULL REFERENCES widgets (id),
                version      integer        NOT NULL,
                config       jsonb          NOT NULL,
                published_by uuid           NOT NULL,
                published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                status       text           NOT NULL DEFAULT 'live',
                note         text,
                created_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_config_versions_status_check CHECK (status IN ('live', 'superseded', 'rolled_back'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_config_versions_unique ON widget_config_versions (tenant_id, widget_id, version)');
        DB::statement('ALTER TABLE widgets ADD CONSTRAINT widgets_live_version_fk FOREIGN KEY (live_version_id) REFERENCES widget_config_versions (id) DEFERRABLE INITIALLY DEFERRED');

        // ── 3. widget_visitor_contexts ────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_visitor_contexts (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                conversation_id   uuid           NOT NULL REFERENCES conversations (id),
                visitor_id        text           NOT NULL,
                url               text,
                referrer          text,
                title             text,
                browser           text,
                os                text,
                device            text,
                viewport_w        integer,
                viewport_h        integer,
                language          text,
                tz_offset_minutes integer,
                custom            jsonb          NOT NULL DEFAULT '{}'::jsonb,
                captured_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_visitor_contexts_conversation ON widget_visitor_contexts (tenant_id, conversation_id)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION widget_visitor_contexts_stamp_captured() RETURNS trigger AS $$
            BEGIN
                NEW.captured_at := clock_timestamp();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER widget_visitor_contexts_captured BEFORE UPDATE ON widget_visitor_contexts FOR EACH ROW EXECUTE FUNCTION widget_visitor_contexts_stamp_captured()');

        // ── 4. widget_health_counters ─────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_health_counters (
                id                      uuid           NOT NULL PRIMARY KEY,
                tenant_id               uuid           NOT NULL REFERENCES tenants (id),
                widget_id               uuid           NOT NULL REFERENCES widgets (id),
                day                     date           NOT NULL,
                loads                   bigint         NOT NULL DEFAULT 0,
                opens                   bigint         NOT NULL DEFAULT 0,
                messages                bigint         NOT NULL DEFAULT 0,
                errors                  bigint         NOT NULL DEFAULT 0,
                invalid_signatures      bigint         NOT NULL DEFAULT 0,
                blocked_origin_attempts bigint         NOT NULL DEFAULT 0,
                mount_fail_csp          bigint         NOT NULL DEFAULT 0,
                double_install          bigint         NOT NULL DEFAULT 0,
                blocked_origins         jsonb          NOT NULL DEFAULT '[]'::jsonb,
                channel_status          text,
                channel_status_at       timestamptz(6),
                created_at              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_health_counters_status_check CHECK (channel_status IS NULL OR channel_status IN ('healthy', 'degraded', 'down'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX widget_health_counters_day ON widget_health_counters (tenant_id, widget_id, day)');

        // ── 5. widget_beacon_samples ──────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE widget_beacon_samples (
                id          uuid           NOT NULL PRIMARY KEY,
                tenant_id   uuid           NOT NULL REFERENCES tenants (id),
                widget_id   uuid           NOT NULL REFERENCES widgets (id),
                kind        text           NOT NULL,
                reason      text,
                sample      text,
                bundle_hash text,
                occurred_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT widget_beacon_samples_kind_check CHECK (kind IN ('load', 'open', 'blocked_origin', 'mount_fail', 'panel_error', 'double_install'))
            )
        SQL);
        DB::statement('CREATE INDEX widget_beacon_samples_recent ON widget_beacon_samples (tenant_id, widget_id, occurred_at DESC)');

        // ── 6. ref_widget_bundles (platform reference data) ───────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE ref_widget_bundles (
                id           uuid           NOT NULL PRIMARY KEY,
                channel      text           NOT NULL,
                bundle_hash  text           NOT NULL,
                loader_hash  text           NOT NULL,
                rollout_pct  smallint       NOT NULL DEFAULT 5,
                killed_at    timestamptz(6),
                published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT ref_widget_bundles_channel_check CHECK (channel IN ('stable', 'early')),
                CONSTRAINT ref_widget_bundles_rollout_check CHECK (rollout_pct BETWEEN 0 AND 100)
            )
        SQL);
        DB::statement('CREATE INDEX ref_widget_bundles_live ON ref_widget_bundles (channel, published_at DESC)');

        // ── RLS on every tenant table (item 6 is ref_* — none) ────────────────
        foreach (['widget_config_versions', 'widget_visitor_contexts', 'widget_health_counters', 'widget_beacon_samples'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid)");
        }

        // ── GRANTS (the APP_WRITABLE_COLUMNS splits filed in the amendment) ──
        DB::statement('GRANT INSERT (name, live_version_id, bundle_channel, test_mode, identity_secret_ref, identity_secret_previous_ref, identity_secret_rotated_at, deleted_at) ON widgets TO app');
        DB::statement('GRANT UPDATE (name, live_version_id, bundle_channel, test_mode, identity_secret_ref, identity_secret_previous_ref, identity_secret_rotated_at, deleted_at) ON widgets TO app');

        DB::statement('GRANT SELECT ON widget_config_versions TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, version, config, published_by, status, note) ON widget_config_versions TO app');
        // widget_config_versions: NO UPDATE, NO DELETE — immutable by grant (FR-20 as physics).

        DB::statement('GRANT SELECT ON widget_visitor_contexts TO app');
        DB::statement('GRANT INSERT (id, tenant_id, conversation_id, visitor_id, url, referrer, title, browser, os, device, viewport_w, viewport_h, language, tz_offset_minutes, custom) ON widget_visitor_contexts TO app');
        DB::statement('GRANT UPDATE (url, referrer, title, browser, os, device, viewport_w, viewport_h, language, tz_offset_minutes, custom, updated_at) ON widget_visitor_contexts TO app');

        DB::statement('GRANT SELECT ON widget_health_counters TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, day, loads, opens, messages, errors, invalid_signatures, blocked_origin_attempts, mount_fail_csp, double_install, blocked_origins, channel_status, channel_status_at) ON widget_health_counters TO app');
        DB::statement('GRANT UPDATE (loads, opens, messages, errors, invalid_signatures, blocked_origin_attempts, mount_fail_csp, double_install, blocked_origins, channel_status, channel_status_at, updated_at) ON widget_health_counters TO app');

        DB::statement('GRANT SELECT ON widget_beacon_samples TO app');
        DB::statement('GRANT INSERT (id, tenant_id, widget_id, kind, reason, sample, bundle_hash) ON widget_beacon_samples TO app');

        DB::statement('GRANT SELECT ON ref_widget_bundles TO app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON ref_widget_bundles TO platform_admin');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS widgets DROP CONSTRAINT IF EXISTS widgets_live_version_fk');
        DB::statement('DROP TABLE IF EXISTS ref_widget_bundles');
        DB::statement('DROP TABLE IF EXISTS widget_beacon_samples');
        DB::statement('DROP TABLE IF EXISTS widget_health_counters');
        DB::statement('DROP TRIGGER IF EXISTS widget_visitor_contexts_captured ON widget_visitor_contexts');
        DB::statement('DROP FUNCTION IF EXISTS widget_visitor_contexts_stamp_captured');
        DB::statement('DROP TABLE IF EXISTS widget_visitor_contexts');
        DB::statement('DROP TABLE IF EXISTS widget_config_versions');
        DB::statement(<<<'SQL'
            ALTER TABLE widgets
                DROP COLUMN IF EXISTS name, DROP COLUMN IF EXISTS live_version_id, DROP COLUMN IF EXISTS bundle_channel,
                DROP COLUMN IF EXISTS test_mode, DROP COLUMN IF EXISTS identity_secret_ref, DROP COLUMN IF EXISTS identity_secret_previous_ref,
                DROP COLUMN IF EXISTS identity_secret_rotated_at, DROP COLUMN IF EXISTS deleted_at
        SQL);
    }
};
```

### tests/Isolation/WidgetGrantPhysicsTest.php (proposed)
```php
<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCHEMA AMENDMENT 7 grant physics (S13 step 2b; countersigned at 2a) — the
 * AutomationGrantPhysicsTest shape for items 1–6. The denied sets are the
 * AMENDMENT'S OWN FILED SPLITS per privilege, asserted EXACTLY (a widened
 * grant is as red as a narrowed one). Executing controls: a config version
 * UPDATE dying 42501 (FR-20 immutable by grant), a widget key_id UPDATE
 * dying 42501 (Doc 02's physics re-asserted), captured_at re-stamped by the
 * fill trigger on UPDATE (R-20 shape), and the ref_* table holding no app
 * write at all.
 */
const DOC13_TABLES = ['widgets', 'widget_config_versions', 'widget_visitor_contexts', 'widget_health_counters', 'widget_beacon_samples'];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC13_DENIED = [
    'widgets' => [
        // Doc 02's split stands (id/tenant/key/allowed_origins/hygiene as S14 filed) + the amendment-7 growth.
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'key_id', 'tenant_id'],
    ],
    'widget_config_versions' => [
        // published_at is ORDERING-RELEVANT (the publish history) — R-19b; hygiene app-denied.
        'INSERT' => ['created_at', 'published_at'],
        'UPDATE' => '*', // immutable by grant (as ruled)
    ],
    'widget_visitor_contexts' => [
        // captured_at is the trigger's (R-20); identity immutable on UPDATE.
        'INSERT' => ['captured_at', 'created_at', 'updated_at'],
        'UPDATE' => ['captured_at', 'conversation_id', 'created_at', 'id', 'tenant_id', 'visitor_id'],
    ],
    'widget_health_counters' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'day', 'id', 'tenant_id', 'widget_id'],
    ],
    'widget_beacon_samples' => [
        'INSERT' => ['occurred_at'],
        'UPDATE' => '*',
    ],
];

it('R-19b/ADR-0003: the app denied set per widget table and privilege is EXACTLY the amendment-7 filing', function (): void {
    foreach (DOC13_TABLES as $table) {
        $columns = doc12Columns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC13_DENIED[$table][$privilege];
            $denied = doc12DeniedSet($table, $privilege);
            if ($expected === '*') {
                expect($denied)->toBe($columns, "{$table} {$privilege}: app should hold NO column grant");
            } else {
                expect($denied)->toBe($expected, "{$table} {$privilege} denied set drifted");
            }
        }
    }
});

it('N-01: every widget tenant table is FORCED RLS with tenant_isolation, app holds no DELETE on any, and ref_widget_bundles is app-READ-ONLY platform reference data', function (): void {
    foreach (DOC13_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
        /** @var object{ok: bool} $del */
        $del = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($del->ok)->toBeFalse("app can DELETE from {$table}");
    }

    foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        /** @var object{ok: bool} $ref */
        $ref = DB::selectOne("SELECT has_table_privilege('app', 'ref_widget_bundles', ?) AS ok", [$privilege]);
        expect($ref->ok)->toBeFalse("app can {$privilege} ref_widget_bundles — the pointer is the release pipeline's");
    }
    /** @var object{ok: bool} $sel */
    $sel = DB::selectOne("SELECT has_table_privilege('app', 'ref_widget_bundles', 'SELECT') AS ok");
    expect($sel->ok)->toBeTrue('the shim must read the pointer');
});

it('FR-20 / Doc 02 physics: a config-version UPDATE and a widgets key_id UPDATE die 42501 — with a lawful publish + pointer flip as the positive control; captured_at is re-stamped by the trigger, never by the app', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);

    // Positive control: INSERT a version, flip the pointer — both granted.
    $versionId = (string) Str::uuid7();
    DB::table('widget_config_versions')->insert([
        'id' => $versionId, 'tenant_id' => $tenant->identifier(), 'widget_id' => $widget->getKey(),
        'version' => 1, 'config' => json_encode(['appearance' => []]), 'published_by' => (string) Str::uuid7(),
    ]);
    DB::table('widgets')->where('id', $widget->getKey())->update(['live_version_id' => $versionId, 'name' => 'Fixture widget']);
    expect(DB::table('widgets')->where('id', $widget->getKey())->value('live_version_id'))->toBe($versionId);

    foreach ([
        "UPDATE widget_config_versions SET config = '{}'::jsonb",
        "UPDATE widget_config_versions SET status = 'superseded'",
        "UPDATE widgets SET key_id = '00000000-0000-0000-0000-000000000000'",
        "UPDATE widget_visitor_contexts SET captured_at = now()",
        'DELETE FROM widget_config_versions',
    ] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }

    // R-20 control: the trigger stamps captured_at on UPDATE from the DB clock.
    [, $conversation] = conversationFixture(); // the house helper: a shell customer + a conversation under the bound tenant
    $conversationId = $conversation->identifier();
    $contextId = (string) Str::uuid7();
    DB::table('widget_visitor_contexts')->insert([
        'id' => $contextId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'visitor_id' => 'v-fixture', 'url' => 'https://customer.harness.localhost:8443/deposit',
    ]);
    $first = DB::table('widget_visitor_contexts')->where('id', $contextId)->value('captured_at');
    DB::statement("SELECT pg_sleep(0.01)");
    DB::table('widget_visitor_contexts')->where('id', $contextId)->update(['url' => 'https://customer.harness.localhost:8443/withdraw']);
    $second = DB::table('widget_visitor_contexts')->where('id', $contextId)->value('captured_at');
    expect($second)->not->toBe($first, 'captured_at did not move on UPDATE — the fill trigger is missing');
});
```

### registries additions at step 2b (proposed; the two gates register at step 6 with their scripts — registry-check requires the command file to exist)
```php
<?php
// ── settings.php additions (Doc 13; every widget.* name in the document is a SETTING — R-D13-4) ──
$settings = [
    // FR-12: staff identity shown to visitors after a human joins (first_name | alias | team_name).
    ['key' => 'widget.agent_identity', 'type' => 'string', 'default' => 'first_name', 'validation' => 'required|in:first_name,alias,team_name', 'tenant_editable' => true, 'owning_doc' => 'Doc13'],
    // FR-14/BR-06/T-06: the proactive ceiling across EVERY rule (one shot per visitor per window).
    ['key' => 'widget.proactive.cooldown_hours', 'type' => 'int', 'default' => 24, 'validation' => 'required|integer|min:1|max:168', 'tenant_editable' => true, 'owning_doc' => 'Doc13'],
    // SEC-05: off = history is server-fetch only (kiosk/shared devices); the IndexedDB cache holds nothing.
    ['key' => 'widget.cache.content', 'type' => 'bool', 'default' => true, 'validation' => 'required|boolean', 'tenant_editable' => true, 'owning_doc' => 'Doc13'],
    // T-03/FR-25 family: per-visitor-token limits (ThrottleVisitorToken); platform dials.
    ['key' => 'widget.rate.messages_per_minute', 'type' => 'int', 'default' => 20, 'validation' => 'required|integer|min:5|max:120', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    ['key' => 'widget.rate.requests_per_minute', 'type' => 'int', 'default' => 120, 'validation' => 'required|integer|min:30|max:600', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-20/NFR-04: open widgets poll config every N seconds (300 = the document's 5 min); pushed on the socket too.
    ['key' => 'widget.config.poll_seconds', 'type' => 'int', 'default' => 300, 'validation' => 'required|integer|min:60|max:900', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-21/FS-01: the polling fallback interval (5 s) — never used while the socket is healthy (NFR-03).
    ['key' => 'widget.polling.interval_seconds', 'type' => 'int', 'default' => 5, 'validation' => 'required|integer|min:2|max:30', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-04: staged rollout stages for the early channel pointer (5 → 50 → 100).
    ['key' => 'widget.bundle.rollout_stages', 'type' => 'json', 'default' => [5, 50, 100], 'validation' => 'required|array|min:1', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-01/FR-19 Install: the origins the snippet and the CSP note render (platform-only; the HARNESS values in dev, Doc 18's in production).
    ['key' => 'widget.cdn_origin', 'type' => 'string', 'default' => '', 'validation' => 'nullable|string|max:255', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    ['key' => 'widget.frame_origin', 'type' => 'string', 'default' => '', 'validation' => 'nullable|string|max:255', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-17: in-page sound default (config: on).
    ['key' => 'widget.sound.default', 'type' => 'bool', 'default' => true, 'validation' => 'required|boolean', 'tenant_editable' => true, 'owning_doc' => 'Doc13'],
    // §8 overflow: transcript mode (emails or downloads per config).
    ['key' => 'widget.transcript.mode', 'type' => 'string', 'default' => 'download', 'validation' => 'required|in:download,email', 'tenant_editable' => true, 'owning_doc' => 'Doc13'],
    // SEC-06: transcript export rate limit per visitor per hour.
    ['key' => 'widget.transcript.rate_per_hour', 'type' => 'int', 'default' => 3, 'validation' => 'required|integer|min:1|max:20', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // T-08: sampled panel error stacks (0..1); counters are ALWAYS incremented.
    ['key' => 'widget.beacon.sample_rate', 'type' => 'string', 'default' => '0.05', 'validation' => 'required|numeric|min:0|max:1', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // SEC-03: the signed-identity secret's overlap window after a rotation (hours).
    ['key' => 'widget.identity.rotation_overlap_hours', 'type' => 'int', 'default' => 24, 'validation' => 'required|integer|min:1|max:168', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
    // FR-24: the operator-declared capture() key allowlist ceiling (keys per widget).
    ['key' => 'widget.context.max_custom_keys', 'type' => 'int', 'default' => 20, 'validation' => 'required|integer|min:0|max:50', 'tenant_editable' => false, 'owning_doc' => 'Doc13'],
];
// ── features.php addition ──
$features = [
    // Doc 13 FR-16: "voice recording behind the platform flag (default off per the series stance)".
    // PLATFORM-scoped (tenant_toggleable false — the emergency_read_only idiom): the adapter's media list and
    // the composer's attach row read it; N-19 — hides, never destroys. Doc 07's inbox.voice_recording is its own.
    ['key' => 'widget.voice_recording', 'label_i18n_key' => 'widget.feature.voice_recording', 'default' => false, 'tenant_toggleable' => false, 'owning_doc' => 'Doc13'],
];
// ── audit-coverage.php additions (every Designer mutation; Doc 17 [D17] visibility classes annotated) ──
$audit = [
    ['action_key' => 'widget.created', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'name'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.updated', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'changed'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.config.published', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'version_id', 'version'], 'owning_doc' => 'Doc13'], // HIGH-VISIBILITY class [D17]
    ['action_key' => 'widget.config.rolled_back', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'from_version', 'to_version', 'new_version_id'], 'owning_doc' => 'Doc13'], // HIGH-VISIBILITY class [D17]
    ['action_key' => 'widget.allowlist.changed', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'added', 'removed'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.secret.rotated', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'overlap_until'], 'owning_doc' => 'Doc13'], // HIGH-VISIBILITY class [D17]; never a ref value
    ['action_key' => 'widget.secret.created', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.test_mode.changed', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'test_mode'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.bundle_channel.changed', 'entity' => 'widget', 'actor_types' => ['staff'], 'required_fields' => ['widget_id', 'old', 'new'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.bundle.pointer_changed', 'entity' => 'ref_widget_bundle', 'actor_types' => ['platform_admin'], 'required_fields' => ['channel', 'bundle_hash', 'rollout_pct', 'killed'], 'owning_doc' => 'Doc13'],
    ['action_key' => 'widget.identity.switched', 'entity' => 'conversation', 'actor_types' => ['system'], 'required_fields' => ['conversation_id', 'previous_identity_id', 'asserted_identity_id'], 'owning_doc' => 'Doc13'], // EC-04's staff-visible switch
];
// ── gates.yaml additions (wired at step 6; registered now so the inventory names them) ──
$gates = <<<'YAML'
  - gate_id: widget-bundle-budget
    name: Widget loader <= 5 KB gz and core panel <= 50 KB gz from Vite's own manifest; Help/forms are lazy chunks (Doc 13 NFR-01, R-05)
    command: bash ci/widget-bundle-budget.sh
    owning_doc: Doc13
    owning_ac: AC-09
    owner: platform-engineering
    added_date: 2026-09-04
    blocking: true
  - gate_id: widget-vocabulary
    name: No failure vocabulary in any widget.* customer string, en + am (Doc 13 §8, R-D13-5.1), with a positive control
    command: php ci/widget-vocabulary.php
    owning_doc: Doc13
    owning_ac: AC-10
    owner: platform-engineering
    added_date: 2026-09-04
    blocking: true
YAML;
```
