<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use App\Models\SmsTemplate;

/**
 * Doc 14 FR-01 / §14 / §21 — the seeded templates: every TEMPLATE event in
 * BOTH launch languages with REAL Ethiopic (never lorem), from
 * `fixtures/sms/templates-seed.json`. Idempotent per (event, language): an
 * existing template is left alone (an operator's edits are never overwritten).
 * The per-event toggles are SETTINGS (the §21 four ON by default); the seed
 * writes templates only. Called by the `sms:seed-templates` console command
 * (the stack seeders, the browser fixtures) and by the setup checklist's
 * "Create default templates" action (step 13). A `tenant.provisioned`
 * consumer would be a catalog amendment and is NOT filed — stated.
 */
final class TemplateSeeder
{
    public function __construct(private readonly TemplateWriter $writer) {}

    /** @return array{created: int, skipped: int} */
    public function seed(string $actorId): array
    {
        $created = 0;
        $skipped = 0;
        foreach (self::fixture() as $entry) {
            $exists = SmsTemplate::query()->where('event', $entry['event'])->where('language', $entry['language'])->whereNull('deleted_at')->exists();
            if ($exists) {
                $skipped++;

                continue;
            }
            $this->writer->create($entry['event'], $entry['language'], $entry['name'], $entry['body'], $actorId, [
                'quiet_hours_policy' => $entry['quiet_hours_policy'],
                'note' => 'seeded (Doc 14 §21)',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return list<array{event: string, language: string, name: string, body: string, quiet_hours_policy: string}> */
    public static function fixture(): array
    {
        $raw = (string) file_get_contents(base_path('fixtures/sms/templates-seed.json'));
        /** @var array{entries: list<array{event: string, language: string, name: string, body: string, quiet_hours_policy: string}>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['entries'];
    }
}
