import { execFileSync } from 'node:child_process';

/** Run a tinker snippet in the app container, returning trimmed stdout. */
function tinker(php: string): string {
    return execFileSync(
        'docker',
        [
            'compose',
            '-f',
            'docker/compose.yaml',
            '-f',
            'docker/compose.dev.yaml',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            php,
        ],
        { encoding: 'utf8' },
    ).trim();
}

/**
 * Seed one SMS timeline row on the Yonas #4820 fixture ticket: an sms_messages
 * row in `state` + the matching sms.<state> event carrying the ticket_id (the
 * timeline door reads events by payload->ticket_id and joins SmsTimelineFacts).
 * Returns the sms_message_id. Idempotent-ish: each call makes a new row.
 */
export function seedSmsRow(state: string, phone = '+251912344417'): string {
    const glyphEvent =
        state === 'delivered' ? 'sms.delivered' : state === 'expired' ? 'sms.expired' : 'sms.sent';
    const out = tinker(
        `$t = DB::connection('pgsql_platform')->table('tickets')->where('number', 4820)->first(['id','tenant_id','conversation_id','customer_id']);` +
            `$id = (string) Str::uuid7();` +
            `App\\Support\\Tenancy\\TenantContext::class;` +
            `DB::connection('pgsql_platform')->table('sms_messages')->insert(['id'=>$id,'tenant_id'=>$t->tenant_id,'customer_id'=>$t->customer_id,'ticket_id'=>$t->id,'conversation_id'=>$t->conversation_id,'event'=>'ticket_resolved','language'=>'en','phone'=>'${phone}','rendered_body'=>'fixture body','state'=>'${state}','lane'=>'sms-transactional','segments'=>1]);` +
            `$eid = (string) Str::uuid7();` +
            `DB::connection('pgsql_platform')->table('events')->insert(['id'=>$eid,'tenant_id'=>$t->tenant_id,'name'=>'${glyphEvent}','schema_version'=>1,'payload'=>json_encode(['tenant_id'=>$t->tenant_id,'sms_message_id'=>$id,'ticket_id'=>$t->id,'event'=>'ticket_resolved','lane'=>'sms-transactional']),'occurred_at'=>now()]);` +
            `echo $id;`,
    );

    return out.split('\n').pop() ?? '';
}

/** Move a seeded row to `delivered` and post the sms.delivered event (US-02: the chip turns green on a real DLR). */
export function markDelivered(smsMessageId: string): void {
    tinker(
        `$m = DB::connection('pgsql_platform')->table('sms_messages')->where('id','${smsMessageId}')->first(['tenant_id','ticket_id']);` +
            `DB::connection('pgsql_platform')->table('sms_messages')->where('id','${smsMessageId}')->update(['state'=>'delivered']);` +
            `$eid = (string) Str::uuid7();` +
            `DB::connection('pgsql_platform')->table('events')->insert(['id'=>$eid,'tenant_id'=>$m->tenant_id,'name'=>'sms.delivered','schema_version'=>1,'payload'=>json_encode(['tenant_id'=>$m->tenant_id,'sms_message_id'=>'${smsMessageId}','ticket_id'=>$m->ticket_id,'event'=>'ticket_resolved','lane'=>'sms-transactional']),'occurred_at'=>now()]);`,
    );
}

/** Clear all SMS rows + sms.* events on the Yonas #4820 ticket — a clean timeline per test. */
export function resetSmsTimeline(): void {
    tinker(
        `$t = DB::connection('pgsql_platform')->table('tickets')->where('number', 4820)->first(['id']);` +
            `DB::connection('pgsql_platform')->table('sms_messages')->where('ticket_id', $t->id)->delete();` +
            `DB::connection('pgsql_platform')->table('events')->where('name', 'like', 'sms.%')->where('payload->ticket_id', $t->id)->delete();`,
    );
}
