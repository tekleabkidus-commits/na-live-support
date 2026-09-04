<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Seeding;

use App\Domain\Forms\FormPublisher;
use App\Domain\Forms\FormWriter;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-06 / FR-15 — the two widget forms Doc 12's contract binds
 * (`FormPreChatContractTest`: the B3-S05 pre-chat and offline shapes), seeded
 * as EDITABLE CONTENT through Doc 12's own public doors (FormWriter,
 * FormPublisher) — a Doc 13-owned seeder, so Doc 12's `FormSeeder` (which
 * pins its three forms by name in its own tests) is never edited: P-SEED as
 * countersigned was WITHDRAWN for this zero-core-edit shape.
 *
 * Presence by name is the arbiter (the Doc 10 BR-07 stance Doc 12 adopted):
 * an operator's edited copy is never overwritten, a rename hides it from
 * re-seeds, forms have no delete. The schemas are byte-for-byte the contract
 * test's fixtures, so the seeded rows and the contract cannot drift.
 *
 * Seeded on the first widget's creation (the Designer, FR-19) and by the
 * HARNESS seeder; re-seeding is a no-op.
 */
final class WidgetFormSeeder
{
    public const PRE_CHAT = 'Pre-chat';

    public const OFFLINE = 'Offline message';

    public function __construct(
        private readonly FormWriter $writer,
        private readonly FormPublisher $publisher,
    ) {}

    /** @return array{forms: int} how many were newly seeded */
    public function seed(string $actorId): array
    {
        $forms = 0;
        foreach (self::forms() as $name => $schema) {
            if (DB::table('forms')->where('name', $name)->exists()) {
                continue;
            }
            $formId = $this->writer->create(['name' => $name, 'entity_bindings' => []], $actorId);
            $this->publisher->publish($formId, $schema, $actorId);
            $forms++;
        }

        return ['forms' => $forms];
    }

    /** @return array<string, array<string, mixed>> name → schema (the contract test's own shapes) */
    public static function forms(): array
    {
        return [
            self::PRE_CHAT => [
                'languages' => ['en', 'am'],
                'steps' => [
                    ['key' => 'identity', 'title' => ['en' => 'So we can find your account', 'am' => 'መለያዎን እንድናገኝ'],
                        'fields' => [
                            // The drawn law (B3-S05 F03/F04): two fields, never more — name and
                            // phone, because that is what finds a betting account.
                            ['key' => 'name', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Name', 'am' => 'ስም']],
                            ['key' => 'phone', 'type' => 'phone', 'required' => true, 'label' => ['en' => 'Phone', 'am' => 'ስልክ']],
                        ]],
                ],
                'completion' => ['tags' => ['pre-chat']],
            ],
            self::OFFLINE => [
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
            ],
        ];
    }
}
