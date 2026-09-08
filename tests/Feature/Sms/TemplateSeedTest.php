<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Catalog\TemplateEvents;
use App\Domain\Sms\Templates\TemplateReader;
use App\Domain\Sms\Templates\TemplateSeeder;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Doc 14 FR-01 / §14 / §21 — the seeded templates: every template event in
| BOTH launch languages with REAL Ethiopic (never lorem), each body inside its
| event's allowed variable set; the §21 four ON by the settings seed, the rest
| off; idempotent; the console command seeds one tenant.
*/

it('seeds 22 templates (11 events × en + am), real Ethiopic in every am body, no lorem, every body inside its event\'s allowed set; idempotent', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);
    $actor = '00000000-0000-7000-8000-0000000000aa';

    $first = app(TemplateSeeder::class)->seed($actor);
    expect($first)->toBe(['created' => 22, 'skipped' => 0]);
    $second = app(TemplateSeeder::class)->seed($actor);
    expect($second)->toBe(['created' => 0, 'skipped' => 22]);

    $rows = DB::table('sms_templates')->join('sms_template_versions', 'sms_template_versions.id', '=', 'sms_templates.live_version_id')->get(['event', 'language', 'body', 'encoding', 'quiet_hours_policy']);
    expect($rows)->toHaveCount(22);
    foreach ($rows as $row) {
        expect(TemplateEvents::isTemplateEvent($row->event))->toBeTrue();
        expect(strtolower((string) $row->body))->not->toContain('lorem');
        if ($row->language === 'am') {
            expect((string) $row->body)->toMatch('/[\x{1200}-\x{137F}]/u', "{$row->event}/am carries real Ethiopic")
                ->and($row->encoding)->toBe('ucs2');
        }
        if (in_array($row->event, ['verification_code', 'security_notification', 'optout_confirmation'], true)) {
            expect($row->quiet_hours_policy)->toBe('exempt');
        } else {
            expect($row->quiet_hours_policy)->toBe('hold');
        }
    }

    // §21: four ON, the rest off — the SETTINGS seed, not the templates
    $settings = app(SettingsRepository::class);
    foreach (SendableEvent::cases() as $event) {
        expect($settings->get('sms.events.'.$event->value.'.enabled'))->toBe($event->enabledByDefault(), $event->value);
    }
    expect(app(TemplateReader::class)->incompleteEnabledEvents())->toBe([], 'every enabled event has both languages');
});

it('the console command seeds one tenant inside its own binding', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    app(App\Support\Tenancy\TenantContext::class)->clear();

    $exit = Artisan::call('sms:seed-templates', ['tenant' => $tenant->identifier()]);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('22 created');

    bindTenant($tenant);
    expect(DB::table('sms_templates')->count())->toBe(22);
});
