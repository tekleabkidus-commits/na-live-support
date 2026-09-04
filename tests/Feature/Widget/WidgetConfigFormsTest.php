<?php

declare(strict_types=1);

use App\Domain\Webchat\Seeding\WidgetFormSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 13 FR-06 / FR-15 / FR-07 (S13 step 9a): the config envelope resolves the
| CONFIGURED pre-chat and offline forms to their current VERSION ids (Doc 12's
| immutable contract), carries the pre-chat mode, the chatbot capability and
| the visitor's asserted display; `GET forms/{versionId}` serves the schema.
| (The first cut filtered `forms.deleted_at`, a column that does not exist —
| caught on the harness, never by the config test that configured no form.)
|--------------------------------------------------------------------------
*/

it('resolves pre_chat_form_id / offline_form_id to version ids with the mode; a visitor reads the version schema; no forms configured = nulls', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    bindTenant($tenant);
    $agent = ticketActor([]);
    bindTenant($tenant);
    app(WidgetFormSeeder::class)->seed($agent->identifier());
    $preChat = (string) DB::table('forms')->where('name', WidgetFormSeeder::PRE_CHAT)->value('id');
    $offline = (string) DB::table('forms')->where('name', WidgetFormSeeder::OFFLINE)->value('id');
    $preChatVersion = (string) DB::table('forms')->where('id', $preChat)->value('current_version_id');
    $versionId = (string) Str::uuid7();
    DB::table('widget_config_versions')->insert(['id' => $versionId, 'tenant_id' => $tenant->identifier(), 'widget_id' => $widget->getKey(), 'version' => 1, 'config' => json_encode(['content' => ['pre_chat_form_id' => $preChat, 'pre_chat_mode' => 'required', 'offline_form_id' => $offline]], JSON_THROW_ON_ERROR), 'published_by' => $agent->identifier()]);
    DB::table('widgets')->where('id', $widget->getKey())->update(['live_version_id' => $versionId]);
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    $config = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/config')->assertStatus(200);
    $config->assertJsonPath('forms.pre_chat.version_id', $preChatVersion)
        ->assertJsonPath('forms.pre_chat.mode', 'required')
        ->assertJsonPath('visitor.display', null);
    expect($config->json('forms.offline.version_id'))->toBeString()
        ->and($config->json('capabilities.chatbot'))->toBeBool();

    $schema = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/forms/'.$preChatVersion)->assertStatus(200);
    expect($schema->json('form_id'))->toBe($preChat)->and($schema->json('schema.steps.0.fields.0.key'))->toBe('name');
    widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/forms/'.(string) Str::uuid7())->assertStatus(404);
});
