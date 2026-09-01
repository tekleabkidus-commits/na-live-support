<?php

declare(strict_types=1);

use App\Domain\Forms\Seeding\FormSeeder;
use App\Domain\Forms\SubmissionWriter;
use App\Domain\Forms\VersionReader;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| B3-S05 CONTRACT FIXTURE (R-D12-2.3) + NFR-04 — the versioned JSON contract
| expresses the DRAWN pre-chat and offline shapes; Doc 13 binds ITS render
| to this contract later ([D13]). The drawn laws: two fields and never more
| (name + phone, because that is what finds a betting account); optional
| variant skips, required variant blocks; the offline form (name, phone,
| what's happened) lands a real submission; Amharic end-to-end.
|--------------------------------------------------------------------------
*/

/** @return array<string, mixed> the drawn two-field pre-chat, as the FR-20 contract */
function preChatSchema(bool $required): array
{
    return [
        'languages' => ['en', 'am'],
        'steps' => [
            ['key' => 'identity', 'title' => ['en' => 'So we can find your account', 'am' => 'መለያዎን እንድናገኝ'],
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'required' => $required, 'label' => ['en' => 'Name', 'am' => 'ስም']],
                    ['key' => 'phone', 'type' => 'phone', 'required' => $required, 'label' => ['en' => 'Phone', 'am' => 'ስልክ']],
                ]],
        ],
        'completion' => ['tags' => ['pre-chat']],
    ];
}

it('B3-S05: the two-field pre-chat publishes in both variants; required BLOCKS an empty submission, optional SKIPS; Amharic rides the contract', function (): void {
    [, $ticket, $agent, $writer, $publisher] = formScene();
    $formId = $writer->create(['name' => 'Fixture Pre-chat'], $agent->identifier());
    $required = $publisher->publish($formId, preChatSchema(required: true), $agent->identifier());
    $optional = $publisher->publish($formId, preChatSchema(required: false), $agent->identifier());
    $submissions = app(SubmissionWriter::class);

    // Required variant: an empty submission is refused — the drawn "blocks the conversation".
    expect(fn () => $submissions->record($ticket->identifier(), $required['version_id'], [], 'webchat'))
        ->toThrow(DomainException::class, 'missing_required');
    // Optional variant: skip is lawful — the drawn "Skip and start typing".
    $submissions->record($ticket->identifier(), $optional['version_id'], [], 'webchat');
    // Filled, in Amharic, on the required variant: lands.
    $submissions->record($ticket->identifier(), $required['version_id'], ['name' => 'አበበ ታደሰ', 'phone' => '+251-900001234'], 'webchat');

    // The contract itself: exactly TWO fields, both languages complete.
    $contract = app(VersionReader::class)->widgetContract($required['version_id']);
    $fields = $contract['schema']['steps'][0]['fields'];
    expect(count($fields))->toBe(2)
        ->and(array_column($fields, 'key'))->toBe(['name', 'phone'])
        ->and($fields[0]['label']['am'])->toBe('ስም');
});

it('B3-S05 offline shape: name + phone + what-happened submits as a real snapshot the receipt can cite; the contract stays language-complete', function (): void {
    [, $ticket, $agent, $writer, $publisher] = formScene();
    $formId = $writer->create(['name' => 'Fixture Offline Form'], $agent->identifier());
    $version = $publisher->publish($formId, [
        'languages' => ['en', 'am'],
        'steps' => [
            ['key' => 'message', 'title' => ['en' => 'Leave this with us', 'am' => 'ይህን ለእኛ ይተዉት'],
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Name', 'am' => 'ስም']],
                    ['key' => 'phone', 'type' => 'phone', 'required' => true, 'label' => ['en' => 'Phone', 'am' => 'ስልክ']],
                    ['key' => 'story', 'type' => 'rich_text', 'required' => true, 'label' => ['en' => "What's happened", 'am' => 'ምን ተከሰተ']],
                ]],
        ],
        'completion' => ['tags' => ['offline']],
    ], $agent->identifier());

    app(SubmissionWriter::class)->record($ticket->identifier(), $version['version_id'], [
        'name' => 'አበበ ታደሰ', 'phone' => '+251-900001234', 'story' => '2,500 ብር አውጥቼ ነበር፣ አልደረሰኝም።',
    ], 'webchat');

    /** @var object{form_data: string, number: int} $row */
    $row = DB::selectOne('SELECT form_data::text AS form_data, number FROM tickets WHERE id = ?', [$ticket->identifier()]);
    $data = json_decode($row->form_data, true);
    // The receipt's substance: a real ticket number + the stored answers.
    expect($row->number)->toBeGreaterThan(0)
        ->and($data['answers']['story'])->toContain('2,500 ብር')
        ->and($data['version_id'])->toBe($version['version_id']);
    // The offline tag rides completion — Doc 13's render consumes it.
    $contract = app(VersionReader::class)->widgetContract($version['version_id']);
    expect($contract['schema']['completion']['tags'])->toBe(['offline']);
});

it('NFR-04: every seeded form\'s widget payload is ≤ 30 KB, and the version contract caches per version id', function (): void {
    [, , $agent] = formScene();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    app(FormSeeder::class)->seed($agent);
    $reader = app(VersionReader::class);

    /** @var list<object{current_version_id: string}> $forms */
    $forms = DB::select('SELECT current_version_id FROM forms WHERE current_version_id IS NOT NULL');
    expect(count($forms))->toBe(3);
    foreach ($forms as $form) {
        expect($reader->payloadBytes($form->current_version_id))->toBeLessThanOrEqual(30 * 1024);
    }
    // Immutable-version cache (infinite in spirit, C-07-finite in physics): the
    // first read populates the per-version key; a second read is served from it.
    $versionId = $forms[0]->current_version_id;
    $cached = app(TenantCache::class)->get('form:version:'.$versionId);
    expect($cached)->not->toBeNull()
        ->and($reader->widgetContract($versionId))->toEqual($cached);
});
