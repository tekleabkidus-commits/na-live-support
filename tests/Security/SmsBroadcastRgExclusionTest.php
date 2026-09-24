<?php

declare(strict_types=1);

use App\Domain\Sms\Broadcasts\AudienceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Feature/Sms/PipelineSupport.php';

/*
| Doc 14 SEC-03 / N-10's neighborhood — THE RG WALL IS STRUCTURAL (written
| FIRST, red on the tree without AudienceQuery — STOP-R 7): the audience
| query carries `WHERE rg_flag = false` IN ITS SQL (asserted on the compiled
| query, not on results), an RG-flagged fixture is NEVER in any audience
| however the filters slice, and clearing the flag readmits the customer —
| the wall is the column, not a guard someone can forget.
*/

it('SEC-03 structural: the compiled audience SQL carries the rg_flag wall; an RG fixture is never in the audience; clearing readmits', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    // a second customer with a phone, RG-FLAGGED
    $flagged = App\Models\Customer::query()->create(['display_name' => 'Fixture RG Player', 'username' => '@fixture_rg_'.Illuminate\Support\Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
    app(App\Domain\Customers\Identity\LinkIdentity::class)->link($flagged->identifier(), 'phone', '+251900009911', $scene['user']->identifier());
    DB::table('customers')->where('id', $flagged->identifier())->update(['rg_flag' => true]);

    $audience = app(AudienceQuery::class);
    $sql = $audience->query([], $scene['user'])->toSql();
    expect($sql)->toContain('"rg_flag" = ?')
        ->and($audience->query([], $scene['user'])->getBindings())->toContain(false);

    $ids = $audience->query([], $scene['user'])->pluck('customers.id')->all();
    expect($ids)->toContain($scene['ticket']->customer_id)
        ->and($ids)->not->toContain($flagged->identifier(), 'an RG-flagged customer is never in ANY audience');

    // however the filters slice: match the flagged customer's own attributes exactly — still excluded
    DB::table('customers')->where('id', $flagged->identifier())->update(['language' => 'am', 'tags' => '{vip}']);
    $sliced = $audience->query(['language' => 'am', 'tags' => ['vip']], $scene['user'])->pluck('customers.id')->all();
    expect($sliced)->toBe([]);

    // clearing the flag readmits (the wall is the COLUMN)
    DB::table('customers')->where('id', $flagged->identifier())->update(['rg_flag' => false]);
    expect($audience->query(['language' => 'am'], $scene['user'])->pluck('customers.id')->all())->toContain($flagged->identifier());
});

it('the audience requires a phone identity and excludes suppressed recipients at materialization', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $phoneless = App\Models\Customer::query()->create(['display_name' => 'Fixture Phoneless', 'username' => '@fixture_np_'.Illuminate\Support\Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);

    $audience = app(AudienceQuery::class);
    $ids = $audience->query([], $scene['user'])->pluck('customers.id')->all();
    expect($ids)->not->toContain($phoneless->identifier(), 'no phone identity → not an SMS audience member');

    // suppression is PER RECIPIENT through the guard — the recipient stays in the
    // audience and lands as a VISIBLE skipped row (AC-06's arithmetic needs it on the books)
    app(App\Domain\Automation\Suppression\MarketingSuppression::class)->suppress($scene['ticket']->customer_id, 'fixture', 'staff', null, null, $scene['user']->identifier());
    expect(array_column($audience->recipients([], $scene['user']), 'customer_id'))->toContain($scene['ticket']->customer_id);
    $result = DB::transaction(fn () => app(App\Domain\Sms\Pipeline\EnqueueSms::class)->enqueue(new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::ServiceAnnouncement, $scene['ticket']->customer_id)));
    expect($result->status)->toBe('skipped')->and($result->reason)->toBe('marketing_suppressed');
});
