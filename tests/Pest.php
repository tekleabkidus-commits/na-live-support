<?php

declare(strict_types=1);

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| RefreshDatabase is deliberately absent — see tests/TestCase.php. The schema is
| built once per process by the migrator role and each test runs in a rolled-back
| transaction, because the `app` role holds no CREATE privilege and must not.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Contract', 'Integration', 'Isolation', 'Security', 'Property', 'Performance', 'Golden');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
|
| Creating a tenant is not a plain insert, and the reason is worth understanding
| because it is load-bearing for the whole isolation design.
|
| `tenants` carries an RLS policy keyed on `id`, so an INSERT is only permitted
| when `app.tenant_id` already equals the id being inserted. That sounds circular
| but it is exactly right: it means creating a tenant needs NO RLS-bypassing role.
| Provisioning generates the id, binds the context to it, and writes — so the
| single most privileged operation in the platform runs as the ordinary `app` role,
| and platform_admin stays reserved for genuine cross-tenant reads (SEC-02).
|
| These helpers do the same thing production does, in the same order.
|
*/

/**
 * Create a tenant and leave the context bound to it.
 *
 * @param  array<string, mixed>  $attributes
 */
function createTenant(array $attributes = []): Tenant
{
    $tenantId = (string) Str::uuid7();
    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    // Bind first: both layers must agree before the row can exist.
    $context->clear();
    $context->bind($tenantId, ResolutionSource::Console);
    $rls->set($tenantId);

    return Tenant::factory()->create(['id' => $tenantId] + $attributes);
}

/**
 * Bind an existing tenant for the remainder of the test.
 *
 * Sets BOTH layers, which is the point: a helper that set only the context would
 * let a test pass while RLS was misconfigured, and a helper that set only the
 * session variable would let one pass while the Eloquent scope was broken. The
 * isolation suite disables one at a time on purpose — everything else binds both.
 */
function bindTenant(Tenant|string $tenant, ResolutionSource $source = ResolutionSource::StaffSession): string
{
    $tenantId = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

    $context = app(TenantContext::class);
    $context->clear();
    $context->bind($tenantId, $source);
    app(RlsSession::class)->set($tenantId);

    return $tenantId;
}

/**
 * Run a callback as a given tenant, restoring the previous binding afterwards.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asTenant(Tenant|string $tenant, callable $callback): mixed
{
    $tenantId = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    $previous = $context->tenantIdOrNull();

    $context->clear();
    $context->bind($tenantId, ResolutionSource::Console);
    $rls->set($tenantId);

    try {
        return $callback();
    } finally {
        $context->clear();

        // RULED CORE EDIT (S08 step-1 checkpoint, ruling 3 — authorized
        // narrowly): when the callback aborted the Postgres transaction, the
        // RLS re-bind here raises 25P02 FROM THE FINALLY, which REPLACES the
        // in-flight exception and masks every real failure as "current
        // transaction is aborted" (it hid four real defects in S08 step 1b).
        // ONLY that case is swallowed: the app-side context still restores,
        // the harness rollback clears the session state, and the REAL error
        // surfaces. Any other QueryException still throws.
        try {
            if ($previous !== null) {
                $context->bind($previous, ResolutionSource::Console);
                $rls->set($previous);
            } else {
                $rls->clear();
            }
        } catch (Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '25P02') {
                throw $e;
            }
        }
    }
}

/**
 * Move a tenant to `active` through the real lifecycle service.
 *
 * Not a factory state, and not an UPDATE. The database trigger refuses any state
 * change that does not carry the lifecycle service's transaction-local flag, so a
 * shortcut here would need to defeat a production control — and a fixture that can
 * do what production cannot is a fixture that proves the wrong thing.
 */
function activateTenant(Tenant $tenant): Tenant
{
    return asTenant($tenant, fn (): Tenant => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Active,
        actor: AuditActor::System,
        reason: 'test fixture',
    ));
}

function suspendTenant(Tenant $tenant): Tenant
{
    return asTenant($tenant, fn (): Tenant => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Suspended,
        actor: AuditActor::PlatformAdmin,
        actorLabel: 'test fixture',
        reason: 'test fixture',
    ));
}

/**
 * Run a callback the way a platform worker runs: with NO tenant bound.
 *
 * The outbox relay, retention jobs and quota reconciliation all read across tenants through
 * PlatformConnection, which REFUSES to start while a tenant context is bound (SEC-02).
 * Test fixtures leave a tenant bound — createTenant() binds, and asTenant() restores that
 * binding — so calling a platform operation straight after a fixture trips the guard. That
 * is the guard being right, not a harness quirk: a relay invoked from inside a
 * tenant-resolved request would be exactly the SEC-02 violation it exists to prevent.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asPlatformWorker(callable $callback): mixed
{
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return $callback();
}

/**
 * Commit the harness transaction so ANOTHER CONNECTION can see the fixtures.
 *
 * Needed by anything that genuinely runs on a second connection in production — the outbox
 * relay, retention, reconciliation — because those go through `pgsql_platform` and an
 * uncommitted transaction on `pgsql` is invisible to it. Faking it (pointing the relay at the
 * test's own connection) would test a code path that does not exist in production and would
 * skip the very cross-tenant read the platform connection is for.
 *
 * The caller must clean up: pass the tenant ids to cleanupTenants() in a finally. Nothing
 * rolls these back.
 */
function commitFixtures(): void
{
    $connection = DB::connection('pgsql');

    if ($connection->transactionLevel() > 0) {
        $connection->commit();
    }
}

/**
 * Delete everything belonging to the given tenants, children before parents.
 *
 * Through the platform connection because the app role holds no DELETE on business tables
 * (BR-02) and RLS would scope the deletes to one tenant at a time anyway.
 *
 * @param  list<string>  $tenantIds
 */
function cleanupTenants(array $tenantIds): void
{
    if ($tenantIds === []) {
        return;
    }

    $platform = DB::connection('pgsql_platform');

    // Doc 11 (SCHEMA AMENDMENT 5, S11 step 2c — the committed-fixture lock proof found
    // cleanup blind to the new tables): departments ↔ queues reference each other
    // (default_queue_id is deferrable), so the department side is cleared first.
    $platform->table('departments')->whereIn('tenant_id', $tenantIds)->update(['default_queue_id' => null]);

    foreach ([
        // Doc 11: decisions reference tickets; memberships/stats/skills/capacity reference
        // users and queues; queue_teams reference teams; queues reference departments.
        'routing_decisions', 'agent_assignment_stats', 'user_routing_capacity', 'user_skills',
        'queue_members', 'user_departments', 'queue_teams', 'team_members', 'queues', 'departments', 'teams',
        // Doc 09 (SCHEMA AMENDMENT 3): nothing references the ai tables, and
        // ai_ticket_summaries references tickets — so all five go first.
        'ai_decisions', 'ai_config_versions', 'ai_kill_switches', 'kb_chunks',
        'ai_ticket_summaries',
        // Doc 10 (SCHEMA AMENDMENT 4, S10 step 2b): children before variants, variants
        // before articles, articles before categories (current_version_id is a soft ref).
        'kb_feedback', 'kb_review_comments', 'kb_slug_history', 'kb_article_versions',
        'kb_article_variants', 'kb_articles', 'kb_categories',
        // Doc 06: everything references tickets, tickets reference conversations —
        // so ticket children first, tickets before conversations. Self-FKs inside
        // tickets (merged_into, origin/previous/parent) are fine in one statement:
        // NO ACTION FK triggers fire at end of statement, when both sides are gone.
        // Doc 07 (S09 step 11 harness fix — the first committed-fixture test
        // with inbox rows found cleanup blind to them): list items reference
        // tickets; the bell/read/views/prefs/selections/status rows reference
        // users — all before tickets and users.
        'ticket_list_items', 'inbox_notifications', 'inbox_read_markers', 'inbox_saved_views',
        'inbox_selection_tokens', 'inbox_user_prefs', 'agent_statuses',
        'ticket_message_anchors', 'ticket_bulk_job_items', 'ticket_bulk_jobs',
        'ticket_merge_snapshots', 'ticket_handoff_packets', 'ticket_followers', 'tickets',
        // Doc 04: messages reference conversations, conversations reference
        // customers — so drafts/revisions/messages first, customers last.
        'message_drafts', 'message_revisions', 'legal_hold_messages', 'message_dead_letters',
        'messages', 'conversations',
        // Doc 08 (same harness fix): every customer child before customers.
        'customer_block_cascade_items', 'customer_field_values', 'customer_identity_conflicts',
        'customer_merge_snapshots', 'customer_notes', 'rg_flag_history',
        'customer_identities', 'customers',
        'events', 'outbox', 'audit_log', 'tenant_settings', 'tenant_sequences',
        'tenant_provisioning_steps', 'tenant_quota_usage', 'widgets', 'webhook_credentials',
        'tenant_keys', 'devices', 'auth_action_tokens', 'user_recovery_codes',
        'user_password_history', 'personal_access_tokens',
        // Doc 03: grant rows reference users AND roles, users.role_id
        // references roles — so overrides/pending first, roles after users.
        'pending_grants', 'user_permission_overrides', 'users', 'roles'] as $table) {
        $platform->table($table)->whereIn('tenant_id', $tenantIds)->delete();
    }

    $platform->table('tenants')->whereIn('id', $tenantIds)->delete();
}

/**
 * Remove a synthetic messages partition: DETACH first (the message_revisions FK
 * clones a dependency onto every attached partition, so a plain DROP is refused),
 * with a throwaway tenant bound on the migrator session — the detach-time FK
 * verification SELECT is policed by message_revisions' FORCE RLS and errors on an
 * unbound current_setting('app.tenant_id'). Safe here ONLY because these synthetic
 * partitions hold no revisions; the step-9 retention job must NOT rely on this
 * check — see the MessagePartitions docblock.
 */
function removeMessagesPartition(string $name): void
{
    $migrator = DB::connection('pgsql_migrator');

    $migrator->statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', false)");

    $attached = $migrator->select("
        SELECT 1
        FROM pg_inherits i
        JOIN pg_class c ON c.oid = i.inhparent
        JOIN pg_class c2 ON c2.oid = i.inhrelid
        WHERE c.relname = 'messages' AND c2.relname = ?
    ", [$name]);

    if ($attached !== []) {
        $migrator->statement(sprintf('ALTER TABLE messages DETACH PARTITION %s', $name));
    }

    $migrator->statement(sprintf('DROP TABLE IF EXISTS %s', $name));
}

/*
|--------------------------------------------------------------------------
| Widget-surface fixtures (Doc 02 step 10 — FR-21/FR-22)
|--------------------------------------------------------------------------
|
| Shared here rather than defined in one suite because Pest only discovers the
| files it is asked to run: a helper defined in WidgetSessionTest.php would
| make `pest tests/Feature/Auth/WidgetTokenTheftTest.php` fatal on an
| undefined function, and targeted runs are the standing iteration mode.
|
*/

const WIDGET_ORIGIN = 'https://help.fixture-operator.example';

/**
 * A tenant with a widget key and (unless told otherwise) its minimal FR-21
 * widget record. Returns [tenant, widget|null, key plaintext] with all
 * context cleared — callers make real HTTP requests from a cold start.
 *
 * @param  list<string>  $origins
 * @return array{0: Tenant, 1: App\Models\Widget|null, 2: string}
 */
function widgetFixture(array $origins = [WIDGET_ORIGIN], bool $withWidgetRecord = true): array
{
    $tenant = createTenant();

    [$widget, $plaintext] = asTenant($tenant, function () use ($origins, $withWidgetRecord): array {
        $issued = app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
            kind: 'widget',
            label: 'fixture widget',
            actor: AuditActor::PlatformAdmin,
        );

        $widget = null;
        if ($withWidgetRecord) {
            $widget = App\Models\Widget::query()->create([
                'key_id' => $issued['key']->getKey(),
                'allowed_origins' => $origins,
            ]);
        }

        return [$widget, $issued['plaintext']];
    });

    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $widget, $plaintext];
}

/**
 * POST /api/widget/v1/session as a page at WIDGET_ORIGIN (override via
 * $headers). $cookie is the raw FR-22 cookie — the widget surface runs no
 * cookie encryption, so it travels unencrypted exactly as in production.
 */
/**
 * The test client accumulates cookies set by earlier requests in the same
 * test (withUnencryptedCookie writes a persistent jar) — so "this request
 * carries NO cookie" must be enforced, not assumed. Without this reset, the
 * theft suite's bare-token request silently rode the victim's earlier cookie
 * and proved nothing.
 */
function resetWidgetClient(): void
{
    app('auth')->forgetGuards();

    $testCase = test();

    // Pest hands back a forwarding proxy, not the TestCase — method calls
    // pass through it, reflection does not.
    if ($testCase instanceof Pest\Support\HigherOrderTapProxy) {
        $testCase = $testCase->target;
    }

    $reflection = new ReflectionObject($testCase);

    foreach (['defaultCookies' => [], 'unencryptedCookies' => [], 'withCredentials' => false] as $property => $empty) {
        $reflected = $reflection->getProperty($property);
        $reflected->setValue($testCase, $empty);
    }
}

function widgetSession(string $keyPlaintext, array $headers = [], array $body = [], ?string $cookie = null): Illuminate\Testing\TestResponse
{
    resetWidgetClient();

    $request = test()->withHeaders(array_merge([
        'Authorization' => 'Bearer '.$keyPlaintext,
        'Origin' => WIDGET_ORIGIN,
    ], $headers));

    if ($cookie !== null) {
        // withCredentials(), or the cookie never leaves the jar: Laravel's
        // JSON test requests mirror fetch() semantics and omit cookies unless
        // credentials mode is on — which is also exactly how the real widget
        // client must call this surface (fetch {credentials:'include'}).
        $request = $request->withUnencryptedCookie('nlw_visitor', $cookie)->withCredentials();
    }

    return $request->postJson('https://localhost/api/widget/v1/session', $body);
}

/**
 * GET the widget context probe as an authenticated visitor (or a pretender —
 * pass a wrong/absent cookie to be one).
 */
function widgetContext(string $keyPlaintext, string $token, ?string $cookie, array $headers = []): Illuminate\Testing\TestResponse
{
    resetWidgetClient();

    $request = test()->withHeaders(array_merge([
        'Authorization' => 'Bearer '.$keyPlaintext,
        'Origin' => WIDGET_ORIGIN,
        'X-Visitor-Token' => $token,
    ], $headers));

    if ($cookie !== null) {
        // See widgetSession() on why withCredentials() is required.
        $request = $request->withUnencryptedCookie('nlw_visitor', $cookie)->withCredentials();
    }

    return $request->getJson('https://localhost/api/widget/v1/context');
}

/**
 * The FR-22 cookie a response set, or fail the test asking for it.
 */
function visitorCookieFrom(Illuminate\Testing\TestResponse $response): string
{
    foreach ($response->baseResponse->headers->getCookies() as $cookie) {
        if ($cookie->getName() === 'nlw_visitor') {
            return (string) $cookie->getValue();
        }
    }

    test()->fail('No nlw_visitor cookie on the response.');
}

/*
|--------------------------------------------------------------------------
| Webhook-surface fixtures (Doc 02 step 11 — FR-23/FR-24/FR-25)
|--------------------------------------------------------------------------
|
| Shared for the same reason as the widget helpers above: targeted runs.
|
*/

/**
 * A tenant with a key of the given kind and (unless $credential is null) its
 * webhook second credential. Returns [tenant, key plaintext, key row id]
 * with all context cleared.
 *
 * @param  array{header_secret_hash?: string, hmac_secret?: string}|null  $credential
 * @return array{0: Tenant, 1: string, 2: string}
 */
function webhookFixture(string $kind, ?array $credential): array
{
    $tenant = createTenant();

    [$plaintext, $keyId] = asTenant($tenant, function () use ($kind, $credential): array {
        $issued = app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
            kind: $kind,
            label: 'fixture '.$kind,
            actor: AuditActor::PlatformAdmin,
        );

        $keyId = $issued['key']->getKey();
        assert(is_string($keyId));

        if ($credential !== null) {
            App\Models\WebhookCredential::query()->create(['key_id' => $keyId] + $credential);
        }

        // Doc 05 S23 step 4: the real webhook handler resolves the BOT behind
        // the key (a key with no bot is 404 by design — the hand-seeded probe
        // era ended when registration became the write path, the S15 standing
        // note's own forecast). Hand-seeded telegram fixtures therefore carry
        // the bot row the wizard would have written.
        if ($kind === 'telegram_bot') {
            App\Models\TelegramBot::query()->create([
                'telegram_bot_id' => random_int(1_000_000_000, 9_999_999_999),
                'username' => 'fixture_seeded_bot',
                'display_name' => 'Fixture Seeded Bot',
                'mode' => 'direct',
                'key_id' => $keyId,
            ]);
        }

        return [$issued['plaintext'], $keyId];
    });

    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $plaintext, $keyId];
}

/**
 * A problem-details body with its per-request members pinned, so two
 * refusals can be compared byte-for-byte on everything that must not vary
 * (AC-07's no-oracle posture; trace_id varies BY DESIGN, C-14).
 */
function normalizedProblemBody(string $content): string
{
    return (string) preg_replace('/"trace_id":"[^"]*"/', '"trace_id":"<trace>"', $content);
}

/**
 * Log capture off the log bus: returns a reference to the live array the
 * listener appends into, so metric assertions read exactly what a request
 * logged (security_metric context keys, C-10 shape).
 *
 * @return list<array{message: string, context: array<string, mixed>}>
 */
function &capturedLogsRef(): array
{
    static $records;
    $records = [];

    Illuminate\Support\Facades\Log::listen(function (Illuminate\Log\Events\MessageLogged $event) use (&$records): void {
        $records[] = ['message' => $event->message, 'context' => $event->context];
    });

    return $records;
}

/*
|--------------------------------------------------------------------------
| RBAC fixtures (Doc 03, S19 — the enforce flip)
|--------------------------------------------------------------------------
|
| Since S19 step 7 every `permission:` declaration is a REAL staff check, so
| a fixture user with no role holds nothing (implicit deny — fail-closed by
| design). Suites that exercise permission-gated routes grant a role first;
| granting everything is the Super Admin template's legitimate shape, not a
| test cheat.
|
*/

/*
|--------------------------------------------------------------------------
| Doc 06 ticket fixtures
|--------------------------------------------------------------------------
|
| Shared here rather than in one suite because several Doc 06 files need the
| same shape and Pest loads them all into one process, where two identically
| named file-local helpers would collide.
|
| The insert is deliberately RAW and names no FR-24 column: the app role has no
| privilege on those (R-19b), so a fixture that tried to seed `resolved_at`
| would fail — which is the point. A fixture needing a chosen metric value
| writes through `pgsql_platform`, exactly as MetricTimestampsTest does.
|
*/

/**
 * Doc 11 (S11 step 2b, advisor-countersigned Option A): a REAL department + its default
 * queue for the BOUND tenant, in OrganizationSeeder's own shape — ONE transaction with
 * default_queue_id set before commit (SCHEMA AMENDMENT 5 FLAG A: this helper cannot mint
 * the defaultless state the service door refuses; the deferrable FK lets the department
 * name its queue before the queue row exists inside the same transaction). It replaces the
 * opaque `Str::uuid7()` department ids Doc 06's suites minted before departments existed —
 * TransferTicket.php:41's "existence validation is Doc 11's arrival gift" arrived with
 * `tickets_department_fk`.
 *
 * @return string the department id
 */
function departmentFixture(string $name = 'Fixture Department', bool $isRg = false): string
{
    $tenantId = app(TenantContext::class)->tenantId();
    $departmentId = (string) Str::uuid7();
    $queueId = (string) Str::uuid7();

    DB::transaction(function () use ($tenantId, $departmentId, $queueId, $name, $isRg): void {
        DB::table('departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $tenantId,
            'name' => json_encode(['en' => $name], JSON_THROW_ON_ERROR),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'default_queue_id' => $queueId,
        ]);
        DB::table('queues')->insert([
            'id' => $queueId,
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'name' => $name,
            'is_rg' => $isRg,
        ]);
    });

    return $departmentId;
}

/**
 * A tenant + customer + conversation + one ticket at the requested status.
 *
 * Claims the conversation's Doc 04 open-ticket slot when the status is
 * open-family and the ticket is not linked work, so the fixture leaves the FR-10
 * invariant in the state the creation path (step 3) will produce for real.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Tenant, 1: App\Models\Conversation, 2: App\Models\Ticket}
 */
function ticketFixture(string $status = 'new', array $attributes = []): array
{
    $tenant = createTenant();

    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_ticket_'.Str::random(6),
        'is_shell' => true,
        'created_source' => 'staff',
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(10),
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);

    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert(array_merge([
        'id' => $ticketId,
        'tenant_id' => $tenant->identifier(),
        'conversation_id' => $conversationId,
        'customer_id' => $customer->identifier(),
        'number' => random_int(1, 2_000_000_000),
        'status' => $status,
        'priority' => 'normal',
        'source' => 'agent',
    ], $attributes));

    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($conversationId);
    /** @var App\Models\Ticket $ticket */
    $ticket = App\Models\Ticket::query()->findOrFail($ticketId);

    $openFamily = in_array($status, ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'], true);
    if ($openFamily && ! ($attributes['linked_work'] ?? false)) {
        app(App\Domain\Conversations\Storage\ConversationRepository::class)
            ->claimOpenTicket($conversation, $ticketId);
    }

    return [$tenant, $conversation, $ticket];
}

/**
 * Another conversation inside the CURRENTLY BOUND tenant.
 *
 * Exists because `ticketFixture()`/`creationFixture()` each create a NEW tenant and
 * rebind the context to it. Calling one of those twice in a test therefore leaves the
 * users, roles and tickets from the first call invisible under RLS — which surfaces as
 * a baffling `assignee_out_of_scope` or `MissingTenantContext` far from the cause. Any
 * test needing several conversations for ONE tenant uses this instead.
 */
function anotherConversation(string $tenantId, string $label = '', ?string $customerId = null): App\Models\Conversation
{
    // $customerId: SEC-06's merge tests need several conversations for ONE customer
    // (a fresh customer per call is the default, and is exactly what the
    // cross-customer refusal tests rely on).
    if ($customerId === null) {
        $customer = App\Models\Customer::query()->create([
            'display_name' => 'Fixture Player',
            'username' => '@fixture_extra_'.Str::random(6),
            'is_shell' => true,
            'created_source' => 'staff',
        ]);
        $customerId = $customer->identifier();
    }

    $id = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.$label.Str::random(10),
        'customer_id' => $customerId,
        'state' => 'active',
    ]);

    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($id);

    return $conversation;
}

/**
 * A staff user in the bound tenant holding exactly the named permission keys.
 *
 * @param  list<string>  $permissions
 */
function ticketActor(array $permissions, string $roleName = 'Fixture Ticket Role'): User
{
    return grantPermissions(User::factory()->create(), $permissions, $roleName);
}

/**
 * The T-03 candidate's score, normalized: integer microseconds when the member is
 * armed, null when it is absent. phpredis returns FALSE for a missing ZSET member
 * (predis returns null), so a raw `zscore(...) === null` absence check is blind
 * under this stack's client — every timer test asks through this helper instead.
 */
function ticketTimerScore(string $member): ?int
{
    $score = Illuminate\Support\Facades\Redis::connection('durable')
        ->zscore(App\Domain\Tickets\Timers\TicketTimers::ZSET, $member);

    return $score === false || $score === null ? null : (int) $score;
}

/**
 * An inbound customer message, the shape InboundPersister writes (no delivery
 * lifecycle) — the FR-08/EC-01 trigger signal (step 5).
 */
function reopenCustomerMessage(string $tenantId, string $conversationId): string
{
    $id = (string) Str::uuid7();

    DB::table('messages')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'direction' => 'in',
        'author_type' => 'customer',
        'kind' => 'text',
        'body' => 'fixture customer follow-up',
        'is_internal' => false,
    ]);

    return $id;
}

/** The relayed message.received event row the reopen consumer re-reads (catalog payload shape). */
function reopenSeedEvent(App\Models\Conversation $conversation, string $messageId): string
{
    $eventId = (string) Str::uuid7();

    DB::table('events')->insert([
        'id' => $eventId,
        'tenant_id' => $conversation->getAttribute('tenant_id'),
        'name' => 'message.received',
        'schema_version' => 1,
        'payload' => json_encode([
            'tenant_id' => $conversation->getAttribute('tenant_id'),
            'message_id' => $messageId,
            'conversation_id' => $conversation->identifier(),
            'customer_id' => $conversation->customer_id,
            'kind' => 'text',
        ], JSON_THROW_ON_ERROR),
    ]);

    return $eventId;
}

/** Run the reopen consumer the way the queue worker does, tenant already bound by the harness. */
function runReopenConsumer(string $eventId): void
{
    (new App\Jobs\Tickets\TicketReopenConsumer($eventId))->handle(
        app(App\Domain\Tickets\Reopen\ReopenTicket::class),
        app(App\Domain\Tickets\Creation\CreateTicket::class),
    );
}

/**
 * A ticket walked THROUGH the machine to `closed` (or `resolved`), so
 * closed_at/resolved_at are the R-20 trigger's real stamps and the FR-10 slot
 * bookkeeping is the service's own — never a raw status insert.
 */
function machineClosedTicket(App\Models\Conversation $conversation, string $finalStatus = 'closed'): App\Models\Ticket
{
    $ticketId = (string) Str::uuid7();

    DB::table('tickets')->insert([
        'id' => $ticketId,
        'tenant_id' => $conversation->getAttribute('tenant_id'),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $conversation->customer_id,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'new',
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    /** @var App\Models\Ticket $ticket */
    $ticket = App\Models\Ticket::query()->findOrFail($ticketId);
    app(App\Domain\Conversations\Storage\ConversationRepository::class)->claimOpenTicket($conversation, $ticketId);

    $service = app(App\Domain\Tickets\Transitions\TicketTransitionService::class);
    $walk = match ($finalStatus) {
        'open' => ['open'],
        'resolved' => ['open', 'resolved'],
        'closed' => ['open', 'resolved', 'closed'],
        default => throw new InvalidArgumentException("machineClosedTicket walks to open|resolved|closed, not {$finalStatus}"),
    };

    foreach ($walk as $status) {
        $service->transition(
            $ticket,
            App\Domain\Tickets\Transitions\TicketStatus::from($status),
            App\Domain\Tickets\Transitions\TransitionContext::system('fixture walk'),
        );
        $ticket->refresh();
    }

    return $ticket;
}

/**
 * Assign the user a role granting every catalog key (all scopes All) —
 * the Super Admin shape. Creates the role once per tenant.
 */
function grantAllPermissions(User $user): User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => 'Fixture Super Role'],
        [
            'color' => 'role-super-admin',
            'permissions' => array_keys(app(App\Support\Rbac\PermissionCatalog::class)->all()),
            'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All', 'ai' => 'All'],
        ],
    );
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

/**
 * Assign a role granting exactly the named keys (scopes All on the four
 * scoped modules) — for under-privilege and boundary fixtures.
 *
 * @param  list<string>  $permissions
 */
function grantPermissions(User $user, array $permissions, string $roleName = 'Fixture Scoped Role'): User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => $permissions,
            'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All', 'ai' => 'All'],
        ],
    );
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Perf-budget helpers (Doc 01 AC-07 / Doc 03 NFR-01 — dev-hardware-indicative)
|--------------------------------------------------------------------------
|
| Shared here so targeted runs of any Performance suite load them; every
| number they print is indicative, and PERF_BUDGETS_ENFORCE=1 (`make perf`)
| asserts the spec budgets exactly. See FoundationBudgetsTest's docblock.
|
*/

const PERF_SAMPLES = 2000;

/**
 * @param  callable(): void  $operation
 * @return array{p50: float, p99: float, max: float} milliseconds
 */
function measure(callable $operation, int $samples = PERF_SAMPLES): array
{
    foreach (range(1, 50) as $i) {
        $operation(); // warm-up: connections, statement cache, opcache
    }

    $timings = [];
    foreach (range(1, $samples) as $i) {
        $start = hrtime(true);
        $operation();
        $timings[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($timings);

    return [
        'p50' => $timings[(int) ($samples * 0.50)],
        'p99' => $timings[(int) ($samples * 0.99)],
        'max' => $timings[$samples - 1],
    ];
}

function reportBudget(string $name, float $budgetMs, array $stats): void
{
    fwrite(STDERR, sprintf(
        "  [dev-hardware-indicative] %-28s p50 %7.3f ms   p99 %7.3f ms   max %7.3f ms   budget(p99) %.1f ms   %s\n",
        $name,
        $stats['p50'],
        $stats['p99'],
        $stats['max'],
        $budgetMs,
        $stats['p99'] <= $budgetMs ? 'WITHIN' : 'OVER',
    ));
}

/** The spec budget under PERF_BUDGETS_ENFORCE=1; a 5× regression ceiling otherwise. */
function assertedBudget(float $specBudgetMs): float
{
    return getenv('PERF_BUDGETS_ENFORCE') === '1' ? $specBudgetMs : $specBudgetMs * 5.0;
}

/**
 * @param  array{p50: float, p99: float, max: float}  $stats
 */
function assertBudget(string $name, array $stats, float $specBudgetMs): void
{
    $ceiling = assertedBudget($specBudgetMs);
    $strict = getenv('PERF_BUDGETS_ENFORCE') === '1';

    expect($stats['p99'])->toBeLessThanOrEqual($ceiling, sprintf(
        '%s: p99 %.3f ms exceeds the asserted %s of %.3f ms (spec budget %.1f ms; p50 %.3f ms, max %.3f ms). '
        .'%s',
        $name,
        $stats['p99'],
        $strict ? 'SPEC BUDGET' : '5x regression ceiling',
        $ceiling,
        $specBudgetMs,
        $stats['p50'],
        $stats['max'],
        $strict
            ? 'Strict mode (`make perf`): this is the spec budget itself, on dev hardware.'
            : 'This is a 5x ceiling, so a failure is either a real order-of-magnitude regression or a '
                .'machine under heavy load — compare p50 against the spec budget to tell which.',
    ));
}

/*
|--------------------------------------------------------------------------
| Secret-hygiene fixtures (Doc 02 step 12 — SEC-08 / AC-08 / C-13)
|--------------------------------------------------------------------------
|
| One driver mints every credential class this document created, through the
| REAL flows — no service shortcuts on the paths where a request could leak.
| The two step-12 suites (LogScrubberTest, SecretsNeverSerializeTest) then
| scan their respective sinks for every value returned here. Shared in
| Pest.php for the standing reason: targeted runs must not fatal on a helper
| defined in a sibling suite file.
|
| Sentinels are self-identifying per CLAUDE.md §8 (`FAKECRED-…`, fixture
| emails); server-generated credentials (TOTP secret, recovery codes, token
| plaintexts, visitor token, reset/invite tokens) are CAPTURED from the real
| responses instead of seeded, which is strictly stronger: the sweep scans
| for the actual live values the platform minted.
|
*/

const SEEDED_STAFF_PASSWORD = 'FAKECRED-staff-Passw0rd-77341';
const SEEDED_WRONG_PASSWORD = 'FAKECRED-wrong-Passw0rd-18262';
const SEEDED_RESET_PASSWORD = 'FAKECRED-reset-Passw0rd-93518';
const SEEDED_INVITE_PASSWORD = 'FAKECRED-invite-Passw0rd-40257';
const SEEDED_TELEGRAM_HEADER_SECRET = 'FAKECRED-telegram-header-secret-4e21b0aa';
const SEEDED_WRONG_TELEGRAM_SECRET = 'FAKECRED-wrong-telegram-secret-59c001db';
const SEEDED_DLR_HMAC_SECRET = 'FAKECRED-dlr-hmac-secret-b90c33dd';

function seededTotp(string $secret): string
{
    return (string) app(PragmaRX\Google2FA\Google2FA::class)
        ->oathTotp($secret, intdiv(CarbonImmutable::now()->getTimestamp(), 30));
}

/**
 * The reset/invite token a queued mailable carries, from the FAKED mail bus.
 *
 * @param  class-string  $mailableClass
 */
function seededTokenFromFakedMail(string $mailableClass): string
{
    $tokens = [];

    Mail::assertQueued($mailableClass, function (object $mail) use (&$tokens): bool {
        $url = $mail instanceof App\Mail\PasswordResetMail ? $mail->resetUrl : $mail->inviteUrl;
        if (preg_match('/token=([0-9a-f]{64})/', $url, $matches) === 1) {
            $tokens[] = $matches[1];
        }

        return true;
    });

    if ($tokens === []) {
        test()->fail(sprintf('No %s token reached the faked mail bus.', $mailableClass));
    }

    return (string) end($tokens);
}

/**
 * The reset/invite token inside a REAL serialized job payload on the
 * database queue — the recorded C-13 carve-out surface itself, used as the
 * capture point when the sweep runs with genuine queue serialization.
 */
function seededTokenFromJobsTable(string $mailableBasename): string
{
    foreach (DB::table('jobs')->pluck('payload') as $payload) {
        $payload = (string) $payload;
        if (str_contains($payload, $mailableBasename) && preg_match('/token=([0-9a-f]{64})/', $payload, $matches) === 1) {
            return $matches[1];
        }
    }

    test()->fail(sprintf('No %s payload with a token found on the database queue.', $mailableBasename));
}

/**
 * Drive every credential-minting flow Doc 02 built and return the values no
 * sink may contain.
 *
 * @param  bool  $mailOnDatabaseQueue  true → auth mail serializes onto the
 *                                     database queue for real (jobs-sink
 *                                     scanning); false → Mail::fake()
 * @param  bool  $staffOnly  true → only the staff-surface flows (used by the
 *                           committed events-relay sweep, whose cleanup must
 *                           stay bounded to one tenant)
 * @return array{sentinels: array<string, string>, tenants: list<string>}
 */
function driveSeededCredentialFlows(bool $mailOnDatabaseQueue, bool $staffOnly = false): array
{
    $sentinels = [];
    $tenants = [];

    // Breach check (T-09): a hard 503 everywhere proves fail-open on the way
    // past and keeps the driver hermetic — no egress, deterministic.
    Http::fake(['*' => Http::response('', 503)]);

    if ($mailOnDatabaseQueue) {
        config(['queue.default' => 'database']);
    } else {
        Mail::fake();
    }

    // ── Staff account: password + login, wrong then right (FR-01/FR-04) ──
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create([
        'email' => 'seeded-staff@fixture.example',
        'password' => Hash::make(SEEDED_STAFF_PASSWORD),
        'password_set_at' => now(),
    ])));
    // BR-07 (S19): the machine-token mint below must name a real role.
    $serviceRoleId = asTenant($tenant, fn () => (string) App\Models\Role::query()
        ->where('name', 'Fixture Super Role')->value('id'));
    activateTenant($tenant);
    $tenants[] = (string) $tenant->getKey();

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    $sentinels['staff_password'] = SEEDED_STAFF_PASSWORD;
    $sentinels['wrong_password'] = SEEDED_WRONG_PASSWORD;

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login', [
        'email' => 'seeded-staff@fixture.example',
        'password' => SEEDED_WRONG_PASSWORD,
    ])->assertStatus(422);

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login', [
        'email' => 'seeded-staff@fixture.example',
        'password' => SEEDED_STAFF_PASSWORD,
    ])->assertOk();

    // ── 2FA enrolment + challenge + recovery code (FR-06/FR-07) ──
    $begin = test()->actingAs($user, 'staff')->postJson('/api/staff/security/2fa');
    $begin->assertOk();
    $secret = (string) $begin->json('secret');

    $code = seededTotp($secret);
    $confirm = test()->actingAs($user, 'staff')->postJson('/api/staff/security/2fa/confirm', [
        'code' => $code,
        'enrolment_id' => $begin->json('enrolment_id'),
    ]);
    $confirm->assertOk();
    $codes = $confirm->json('recovery_codes');

    $sentinels['totp_secret'] = $secret;
    $sentinels['totp_code'] = $code;
    $sentinels['recovery_code_used'] = (string) $codes[0];
    $sentinels['recovery_code_unused'] = (string) $codes[1];

    // The challenge path, through the real pending state: TOTP once, then a
    // recovery code, so both credential kinds transit a real request.
    test()->flushSession();
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login', [
        'email' => 'seeded-staff@fixture.example',
        'password' => SEEDED_STAFF_PASSWORD,
    ])->assertStatus(401)->assertJsonPath('status', 'two_factor_required');
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login/two-factor', ['code' => seededTotp($secret)])->assertOk();

    test()->flushSession();
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login', [
        'email' => 'seeded-staff@fixture.example',
        'password' => SEEDED_STAFF_PASSWORD,
    ])->assertStatus(401);
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/login/two-factor', ['recovery_code' => (string) $codes[0]])->assertOk();

    // ── API tokens, personal + machine (FR-18/FR-19) ──
    $pat = test()->actingAs($user, 'staff')->postJson('https://localhost/api/staff/security/tokens', [
        'name' => 'seeded integration',
        'abilities' => ['settings.view'],
        'expiry' => '90',
    ]);
    $pat->assertCreated();
    $sentinels['pat_plaintext'] = (string) $pat->json('plaintext');

    $machine = test()->actingAs($user, 'staff')->postJson('https://localhost/api/staff/admin/machine-tokens', [
        'service_account' => 'Seeded Bot',
        'name' => 'seeded reporting',
        'abilities' => ['settings.view'],
        'expiry' => '90',
        'role_id' => $serviceRoleId,
    ]);
    $machine->assertCreated();
    $sentinels['machine_token_plaintext'] = (string) $machine->json('plaintext');

    // ── Password reset (FR-16) ──
    test()->flushSession();
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/forgot-password', ['email' => 'seeded-staff@fixture.example'])->assertOk();

    $resetToken = $mailOnDatabaseQueue
        ? seededTokenFromJobsTable('PasswordResetMail')
        : seededTokenFromFakedMail(App\Mail\PasswordResetMail::class);
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/reset-password', [
        'token' => $resetToken,
        'password' => SEEDED_RESET_PASSWORD,
        'password_confirmation' => SEEDED_RESET_PASSWORD,
    ])->assertOk();
    $sentinels['reset_token'] = $resetToken;
    $sentinels['reset_password'] = SEEDED_RESET_PASSWORD;

    // ── Invitation (FR-17) ──
    $invited = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'seeded-invited@fixture.example',
        'password' => Hash::make(bin2hex(random_bytes(32))),
        'password_set_at' => null,
    ]));
    asTenant($tenant, fn () => app(App\Domain\Auth\Password\InvitationIssuer::class)->issue(
        $invited,
        CarbonImmutable::now(),
        Illuminate\Http\Request::create('https://localhost/x', 'POST'),
    ));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $inviteToken = $mailOnDatabaseQueue
        ? seededTokenFromJobsTable('InvitationMail')
        : seededTokenFromFakedMail(App\Mail\InvitationMail::class);
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->postJson('https://localhost/invitation', [
        'token' => $inviteToken,
        'password' => SEEDED_INVITE_PASSWORD,
        'password_confirmation' => SEEDED_INVITE_PASSWORD,
    ])->assertOk();
    $sentinels['invite_token'] = $inviteToken;
    $sentinels['invite_password'] = SEEDED_INVITE_PASSWORD;

    if ($staffOnly) {
        return ['sentinels' => $sentinels, 'tenants' => $tenants];
    }

    // ── Widget visitor session (FR-21/FR-22) ──
    [$widgetTenant, , $widgetKey] = widgetFixture();
    $tenants[] = (string) $widgetTenant->getKey();

    $issue = widgetSession($widgetKey);
    $issue->assertCreated();
    $visitorToken = (string) $issue->json('token');
    $visitorCookie = visitorCookieFrom($issue);
    widgetContext($widgetKey, $visitorToken, $visitorCookie)->assertOk();

    $sentinels['widget_key_plaintext'] = $widgetKey;
    $sentinels['visitor_token'] = $visitorToken;
    $sentinels['visitor_cookie'] = $visitorCookie;

    // ── Telegram webhook second credential (FR-23), match + mismatch ──
    [$telegramTenant, $telegramKey] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', SEEDED_TELEGRAM_HEADER_SECRET),
    ]);
    $tenants[] = (string) $telegramTenant->getKey();

    test()->postJson('https://localhost/api/webhooks/telegram', ['ping' => 'seeded'], [
        'Authorization' => 'Bearer '.$telegramKey,
        'X-Telegram-Bot-Api-Secret-Token' => SEEDED_TELEGRAM_HEADER_SECRET,
    ])->assertStatus(202);
    test()->postJson('https://localhost/api/webhooks/telegram', ['ping' => 'seeded'], [
        'Authorization' => 'Bearer '.$telegramKey,
        'X-Telegram-Bot-Api-Secret-Token' => SEEDED_WRONG_TELEGRAM_SECRET,
    ])->assertStatus(403);

    $sentinels['telegram_key_plaintext'] = $telegramKey;
    $sentinels['telegram_header_secret'] = SEEDED_TELEGRAM_HEADER_SECRET;
    $sentinels['telegram_wrong_secret'] = SEEDED_WRONG_TELEGRAM_SECRET;

    // ── SMS DLR HMAC (FR-24), signed + tampered ──
    [$dlrTenant, $dlrKey] = webhookFixture('sms_gateway', ['hmac_secret' => SEEDED_DLR_HMAC_SECRET]);
    $tenants[] = (string) $dlrTenant->getKey();

    $dlrBody = '{"message_id":"seeded-dlr-1","status":"delivered"}';
    $timestamp = (string) CarbonImmutable::now()->getTimestamp();
    $nonce = 'seeded-nonce-'.bin2hex(random_bytes(8));
    $signature = hash_hmac('sha256', sprintf('%s.%s.%s', $timestamp, $nonce, $dlrBody), SEEDED_DLR_HMAC_SECRET);

    $signedDlrServer = static fn (string $sig): array => [
        'HTTP_AUTHORIZATION' => 'Bearer '.$dlrKey,
        'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
        'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_X_WEBHOOK_NONCE' => $nonce,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ];

    test()->call('POST', 'https://localhost/api/webhooks/sms/dlr', [], [], [], $signedDlrServer($signature), $dlrBody)
        ->assertStatus(202);
    test()->call('POST', 'https://localhost/api/webhooks/sms/dlr', [], [], [], $signedDlrServer(str_repeat('ab', 32)), $dlrBody)
        ->assertStatus(403);

    $sentinels['dlr_key_plaintext'] = $dlrKey;
    $sentinels['dlr_hmac_secret'] = SEEDED_DLR_HMAC_SECRET;
    $sentinels['dlr_signature'] = $signature;

    return ['sentinels' => $sentinels, 'tenants' => $tenants];
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a query returns nothing BECAUSE of isolation, not because of an empty
 * database.
 *
 * A test that asserts "zero rows" proves very little on its own — a broken
 * fixture, a typo'd id and working isolation all produce zero. This expectation
 * takes the count a privileged reader sees, so the assertion becomes "the rows
 * exist and this tenant cannot see them", which is the property AC-01 is actually
 * about.
 */
expect()->extend('toBeInvisibleGiven', function (int $rowsThatExist) {
    expect($rowsThatExist)->toBeGreaterThan(
        0,
        'this assertion is vacuous unless the other tenant\'s rows actually exist — check the fixture',
    );

    return $this->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Doc 07 (S07) inbox read-model helpers
|--------------------------------------------------------------------------
*/

/** An events row in the catalog's payload shape; the projector consumer re-reads it (C-09 posture). */
function inboxSeedEvent(string $name, array $payload): string
{
    $eventId = (string) Str::uuid7();

    DB::table('events')->insert([
        'id' => $eventId,
        'tenant_id' => $payload['tenant_id'],
        'name' => $name,
        'schema_version' => 1,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    return $eventId;
}

function runListProjector(string $eventId): void
{
    (new App\Jobs\Inbox\ListProjectorConsumer($eventId))->handle(
        app(App\Domain\Inbox\ReadModels\ListProjector::class),
        app(App\Domain\Inbox\ReadModels\ProjectorStatus::class),
    );
}

/** The list-item row minus volatile maintenance metadata, for equality asserts. */
function listItemState(string $ticketId): array
{
    $row = (array) DB::table('ticket_list_items')->where('ticket_id', $ticketId)->first();
    unset($row['updated_at'], $row['created_at']);

    return $row;
}

/**
 * A ticket row inside the CURRENTLY BOUND tenant (the anotherConversation
 * discipline — ticketFixture would mint and rebind a fresh tenant).
 *
 * @return array{0: App\Models\Ticket}
 */
function inboxTicketRow(string $tenantId, App\Models\Conversation $conversation, string $status = 'open', array $attributes = []): array
{
    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert(array_merge([
        'id' => $ticketId,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $conversation->customer_id,
        'number' => random_int(1, 2_000_000_000),
        'status' => $status,
        'priority' => 'normal',
        'source' => 'agent',
    ], $attributes));

    /** @var App\Models\Ticket $ticket */
    $ticket = App\Models\Ticket::query()->findOrFail($ticketId);

    if (in_array($status, ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'], true)) {
        app(App\Domain\Conversations\Storage\ConversationRepository::class)
            ->claimOpenTicket($conversation, $ticketId);
    }

    return [$ticket];
}

/** A user whose role scopes tickets at the given level, holding tickets.view (S07). */
function inboxScopedActor(string $level, string $roleName): User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            // views.manage per R-26: these are WORKSPACE actors (the ruling's
            // audience/refusal proofs use the REAL seeded templates instead).
            'permissions' => ['tickets.view', 'conversations.view', 'views.manage'],
            'scopes' => ['tickets' => $level, 'conversations' => $level, 'customers' => $level, 'reports' => $level],
        ],
    );
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

// ── S08 (Doc 08) legacy-shape seeders — pre-amendment column shapes, used by
//    IdentityBackfillTest / IdentityInvariantsTest to reproduce the data the
//    SCHEMA AMENDMENT 2 backfill migrates. Synthetic only (+2519xxx fixtures).
function legacyCustomer(string $tenantId, string $createdAt, ?string $phone = null, ?string $username = null, ?string $id = null): string
{
    $id ??= (string) new Symfony\Component\Uid\UuidV7;

    DB::table('customers')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'display_name' => 'Fixture '.substr($id, -4),
        'username' => $username,
        'phone' => $phone,
        'is_shell' => true,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function legacyIdentity(string $tenantId, string $customerId, string $channel, string $externalId, string $createdAt): string
{
    $id = (string) new Symfony\Component\Uid\UuidV7;

    DB::table('customer_identities')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'channel' => $channel,
        'external_user_id' => $externalId,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

/**
 * S08 enforce-toggle (the migrator-connection instrument): the ENFORCE
 * migration makes legacy-shaped rows impossible, but the backfill/enforce
 * suites exist precisely to prove how legacy shapes were handled — so they
 * re-open the pre-enforce window per test, and every suite that needs the
 * enforced world closes it first. DDL rides pgsql_migrator; call ONLY at the
 * top of a test/beforeEach, before the test transaction touches these tables
 * (an ALTER behind row locks would hang — the failFast lesson).
 */
function legacyIdentityShapeAllow(): void
{
    foreach (['type', 'value', 'source'] as $column) {
        DB::connection('pgsql_migrator')
            ->statement("ALTER TABLE customer_identities ALTER COLUMN {$column} DROP NOT NULL");
    }
    DB::connection('pgsql_migrator')
        ->statement('ALTER TABLE customers ALTER COLUMN created_source DROP NOT NULL');
}

function legacyIdentityShapeEnforce(): void
{
    foreach (['type', 'value', 'source'] as $column) {
        DB::connection('pgsql_migrator')
            ->statement("ALTER TABLE customer_identities ALTER COLUMN {$column} SET NOT NULL");
    }
    DB::connection('pgsql_migrator')
        ->statement('ALTER TABLE customers ALTER COLUMN created_source SET NOT NULL');
}

/** A post-enforce customer seed (created_source supplied, unlike legacyCustomer). */
function enforcedCustomer(string $tenantId, string $createdAt, ?string $id = null): string
{
    $id ??= (string) new Symfony\Component\Uid\UuidV7;

    DB::table('customers')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'display_name' => 'Fixture '.substr($id, -4),
        'is_shell' => true,
        'created_source' => 'staff',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

/** A raw ticket row for rollup/move-set fixtures (S08; status chosen directly). */
function rollupTicket(string $tenantId, string $conversationId, string $customerId, string $status): string
{
    $id = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => $status,
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    return $id;
}

/** Seed a conversation with a CHOSEN external_chat_id (the A-42 anchor key; S08). */
function anchoredConversation(string $tenantId, string $customerId, string $externalChatId, string $channelRef = 'fixture_bot'): string
{
    $id = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'channel' => 'telegram',
        'channel_ref_id' => $channelRef, 'external_chat_id' => $externalChatId,
        'customer_id' => $customerId, 'state' => 'active',
    ]);

    return $id;
}

// ── S08 step 8 (Doc 08 T-04/AC-07) — customers_index helpers, shared here per
//    the rollupTicket lesson (two suites consume them: the scope proofs and
//    the directory/indexer suites).

/** A viewer whose CUSTOMERS-module scope is exactly $level ('none' = the module stays silent → fail-closed). */
function customersScopedActor(string $level, string $roleName): User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => ['customers.view'],
            'scopes' => $level === 'none' ? [] : ['customers' => $level],
        ],
    );
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

/** Index one customer's document from truth (the writer the durable indexer calls). */
function indexCustomer(string $customerId): void
{
    app(App\Domain\Customers\Search\CustomerSearchDocuments::class)->writeFor($customerId);
}

/** Drop the ambient tenant's customers index (Meilisearch state survives DB rollback — tests clean up). */
function dropCustomersSearchIndex(): void
{
    $index = app(App\Domain\Customers\Search\CustomerSearchIndex::class);
    try {
        $client = $index->client();
        $task = $client->deleteIndex($index->customersIndexName());
        $client->waitForTask($task['taskUid']);
    } catch (Throwable) {
        // absent index on a cold tenant — nothing to drop
    }
}

/**
 * Doc 09 (S09 step 7): the fake channel the AI pipeline sends through in
 * tests — registered once per process (the OutboundPipelineTest shape).
 */
function registerFakeChannel(): void
{
    $registry = app(App\Domain\Conversations\Adapters\AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new Tests\Support\Adapters\FakeChannelAdapter(new App\Domain\Conversations\Adapters\Capabilities(
            maxText: 4096, mediaTypes: ['photo'], supportsTyping: false, supportsEdit: false,
            supportsDelete: false, supportsButtons: false, replyWindow: App\Domain\Conversations\Adapters\ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }
}

/**
 * Doc 09 decision-record fixture (shared by DecisionLogTest and DecisionAccessTest —
 * moved here at S09 step 9 so neither file depends on the other's load order).
 *
 * @param  array<string, mixed>  $overrides
 */
function decisionFixture(array $overrides = []): App\Domain\Ai\DecisionLog\DecisionRecord
{
    $defaults = [
        'id' => (string) Str::uuid7(),
        'surface' => 'chatbot',
        'task' => 'classify_message',
        'provider' => 'fake',
        'model' => 'fixture-model',
        'layerHashes' => ['immutable' => App\Domain\Ai\Prompt\ImmutableLayer::hash(), 'task_template' => 'abc'],
    ];

    return new App\Domain\Ai\DecisionLog\DecisionRecord(...array_merge($defaults, $overrides));
}

/*
|--------------------------------------------------------------------------
| Doc 09 step 10 — Copilot scene helpers (shared by CopilotPanelTest,
| ComposerActionsTest, ArtifactOutcomeTest, TranslateTest, SimilarTicketsTest,
| StreamingTest and Security/CopilotNeverAutoSendTest).
|--------------------------------------------------------------------------
*/

/**
 * An OPEN ticket's conversation in a fresh tenant, projected into the list
 * read model (the scope filter's source), a staff actor scoped All holding
 * the Copilot keys, thresholds published, the fake channel registered.
 *
 * @return array{0: Tenant, 1: string, 2: App\Models\Conversation, 3: App\Models\Ticket, 4: User}
 */
function copilotScene(array $permissions = ['tickets.view', 'conversations.view', 'tickets.reply', 'tickets.note', 'ai.copilot.use', 'ai.copilot.insert_draft']): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    registerFakeChannel();
    activateTenant($tenant);
    bindTenant($tenant);
    Illuminate\Support\Facades\Cache::flush();

    $actor = inboxScopedActor('All', 'S09 Copilot');
    grantPermissions($actor, $permissions, 'S09 Copilot Role');

    $configs = app(App\Domain\Ai\Config\ConfigVersions::class);
    $v = $configs->draft('thresholds', 'default', ['chatbot_enabled' => false, 'rg_min' => 0.8, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'spam_min' => 0.9], (string) Str::uuid7());
    $configs->publish($v, (string) Str::uuid7());

    return [$tenant, $tenantId, $conversation, $ticket, $actor];
}

/** A scripted provider bound to every tier (fast, strong, embeddings). */
function copilotDriver(App\Domain\Ai\Providers\ProviderResponse|Throwable ...$responses): App\Domain\Ai\Providers\FakeDriver
{
    $driver = new App\Domain\Ai\Providers\FakeDriver(new App\Domain\Ai\Guards\FixturesOnlyEgress);
    foreach ($responses as $response) {
        $driver->queue($response);
    }
    app(App\Domain\Ai\Providers\ProviderRegistry::class)->swap('fake-copilot', $driver);
    config([
        'ai.default_routing.fast' => [['provider' => 'fake-copilot', 'model' => 'm']],
        'ai.default_routing.strong' => [['provider' => 'fake-copilot', 'model' => 'm']],
        'ai.default_routing.embeddings' => [['provider' => 'fake-copilot', 'model' => 'e']],
    ]);

    return $driver;
}

/** A persisted customer message (no pipeline) + its classification decision row. */
function copilotCustomerMessage(string $tenantId, App\Models\Conversation $conversation, string $body, string $language = 'en', array $classification = []): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $conversation->customer_id, 'kind' => 'text', 'body' => $body, 'language' => $language]);

    $classification = array_merge(['intent' => 'deposits', 'sentiment' => -1, 'urgency' => 2, 'spam' => 0.0, 'rg_risk' => 0.05, 'confidence' => 0.82], $classification);
    $writer = app(App\Domain\Ai\DecisionLog\DecisionWriter::class);
    $writer->record(decisionFixture([
        'conversationId' => $conversation->identifier(), 'messageId' => $id, 'language' => $language,
        'intent' => $classification['intent'], 'confidence' => $classification['confidence'], 'rgRisk' => $classification['rg_risk'],
        'responsePayload' => ['classification' => $classification],
    ]));
    $writer->flush();

    return $id;
}

/** A fixture embedding: one hot component. */
function copilotVector(int $hot): array
{
    $v = array_fill(0, 1024, 0.0);
    $v[$hot] = 1.0;

    return $v;
}

function copilotEmbedding(int $hot): App\Domain\Ai\Providers\ProviderResponse
{
    return new App\Domain\Ai\Providers\ProviderResponse('', 0, 0, [], [copilotVector($hot)]);
}

/*
|--------------------------------------------------------------------------
| Doc 09 step 11 — backdated decision-row helpers (shared by SupervisorTest and
| AiBellDeliveryTest; moved here so either file runs alone).
|--------------------------------------------------------------------------
*/

/**
 * Backdated decision rows need created_at (R-19a: platform-only) → the
 * platform connection over COMMITTED fixtures (the PayloadReductionJobTest
 * pattern); every test here cleans its tenant up in a finally.
 */
function supervisorScene(): array
{
    $scene = copilotScene();
    commitFixtures();
    bindTenant($scene[0]);

    return $scene;
}

function platformDecisionRow(string $tenantId, array $columns, string $createdAtSql): void
{
    $cols = array_keys($columns);
    $placeholders = array_map(static fn (string $c): string => in_array($c, ['layer_hashes', 'response_payload'], true) ? '?::jsonb' : '?', $cols);
    asPlatformWorker(fn () => DB::connection('pgsql_platform')->statement(
        sprintf('INSERT INTO ai_decisions (%s, created_at) VALUES (%s, %s)', implode(', ', $cols), implode(', ', $placeholders), $createdAtSql),
        array_values($columns),
    ));
    bindTenant($tenantId);
}

function classifyRow(string $tenantId, string $conversationId, string $intent, int $sentiment, string $createdAtSql): void
{
    platformDecisionRow($tenantId, [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'surface' => 'chatbot', 'task' => 'classify_message', 'provider' => 'fake', 'model' => 'm',
        'layer_hashes' => '{}', 'conversation_id' => $conversationId, 'intent' => $intent, 'confidence' => 0.9, 'action' => 'copilot_context',
        'response_payload' => json_encode(['classification' => ['intent' => $intent, 'sentiment' => $sentiment, 'urgency' => 1, 'spam' => 0.0, 'rg_risk' => 0.0, 'confidence' => 0.9]]),
    ], $createdAtSql);
}

/**
 * The relay's EFFECT for one published event, in-harness: copy the newest
 * outbox row of $name into `events` (same id, same payload) and return its
 * id — so a consumer test drives the REAL published payload, not a hand-made
 * one. (The relay itself runs on the platform connection and cannot see the
 * harness transaction.)
 */
function relayOutboxRow(string $name): string
{
    /** @var object{id: string, tenant_id: string, name: string, payload: string, schema_version?: int}|null $row */
    $row = DB::table('outbox')->where('name', $name)->orderByDesc('id')->first();
    if ($row === null) {
        throw new RuntimeException("no outbox row named {$name} was published");
    }
    DB::table('events')->insertOrIgnore([
        'id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name,
        'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload,
    ]);

    return $row->id;
}

/**
 * Doc 11 (S11 step 5): a SECOND conversation for the bound tenant, mirroring creationFixture()'s
 * shape without minting a new tenant (the S11 step-2c catch: per-ticket creationFixture() calls put
 * every ticket in a different tenant). Returns [tenant-id, Conversation].
 *
 * @return array{0: string, 1: App\Models\Conversation}
 */
function conversationFixture(): array
{
    $tenantId = app(TenantContext::class)->tenantId();
    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_conv_'.Str::random(6),
        'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenantId,
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(10),
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);
    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($conversationId);

    return [$tenantId, $conversation];
}
