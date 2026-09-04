<?php

declare(strict_types=1);

use App\Domain\Forms\VersionReader;
use App\Domain\Webchat\Seeding\WidgetFormSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-06/FR-15 seeds through Doc 12's doors (WidgetFormSeeder — P-SEED
 * withdrawn for this zero-core-edit shape): the two forms exist, published,
 * each with ONE immutable version; the pre-chat form carries EXACTLY the two
 * drawn fields; the offline form carries the `offline` completion tag Doc 06
 * FR-14 consumes; NFR-04's 30 KB widget payload budget holds; re-seeding is a
 * no-op and an operator's edit is never overwritten.
 */
it('seeds the pre-chat and offline forms once, byte-equal to the Doc 12 contract shapes, and never overwrites an operator copy', function (): void {
    [, , $agent] = formScene();
    $seeder = app(WidgetFormSeeder::class);

    expect($seeder->seed($agent->identifier()))->toBe(['forms' => 2]);
    expect($seeder->seed($agent->identifier()))->toBe(['forms' => 0], 'a re-seed must be a no-op');

    /** @var list<object{name: string, current_version_id: ?string}> $rows */
    $rows = DB::select('SELECT name, current_version_id FROM forms WHERE name IN (?, ?) ORDER BY name', [WidgetFormSeeder::OFFLINE, WidgetFormSeeder::PRE_CHAT]);
    expect(array_column($rows, 'name'))->toBe([WidgetFormSeeder::OFFLINE, WidgetFormSeeder::PRE_CHAT]);
    foreach ($rows as $row) {
        expect($row->current_version_id)->not->toBeNull("{$row->name} is not published");
    }

    $reader = app(VersionReader::class);
    $preChat = $reader->widgetContract((string) $rows[1]->current_version_id);
    $fields = $preChat['schema']['steps'][0]['fields'];
    expect(count($fields))->toBe(2, 'the drawn law: two fields, never more')
        ->and(array_column($fields, 'key'))->toBe(['name', 'phone'])
        ->and($fields[0]['label']['am'])->toBe('ስም')
        ->and($preChat['schema']['completion']['tags'])->toBe(['pre-chat'])
        ->and(strlen((string) json_encode($preChat)))->toBeLessThanOrEqual(30 * 1024, 'NFR-04: the widget payload budget');

    $offline = $reader->widgetContract((string) $rows[0]->current_version_id);
    expect(array_column($offline['schema']['steps'][0]['fields'], 'key'))->toBe(['name', 'phone', 'story'])
        ->and($offline['schema']['completion']['tags'])->toBe(['offline']);

    // Doc 12's own three seeded forms are untouched by name.
    expect(DB::table('forms')->whereIn('name', ['Deposit Issue', 'Withdrawal Issue', 'Verification Request'])->count())->toBe(0, 'this seeder must not touch Doc 12\'s forms');
});
