<?php

declare(strict_types=1);

namespace App\Domain\Forms\Seeding;

use App\Domain\Automation\Rules\RuleWriter;
use App\Domain\Forms\FormPublisher;
use App\Domain\Forms\FormWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-23 / §21 rec 3 — the three seeded forms (Deposit Issue,
 * Withdrawal Issue, Verification Request) as EDITABLE CONTENT (Doc 10 BR-07
 * stance: seeded, then the tenant's own — a form the operator already has is
 * never overwritten; presence by name is the arbiter, so an operator's rename
 * makes their copy invisible to re-seeds and a delete cannot happen — forms
 * have no delete), plus three example automation rules created and LEFT
 * DEACTIVATED (rules are born inactive; activation is a deliberate,
 * separately-permissioned act — FR-07).
 */
final class FormSeeder
{
    /** @return array{forms: int, rules: int} how many of each were newly seeded */
    public function seed(User $author): array
    {
        $forms = 0;
        foreach (self::forms() as $name => $seed) {
            if (DB::table('forms')->where('name', $name)->exists()) {
                continue;
            }
            $formId = $this->writer->create(['name' => $name, 'entity_bindings' => $this->bindings($seed['department'])], $author->identifier());
            $this->publisher->publish($formId, $seed['schema'], $author->identifier());
            $forms++;
        }
        $rules = 0;
        foreach (self::exampleRules() as $name => $definition) {
            if (DB::table('automation_rules')->where('name', $name)->exists()) {
                continue;
            }
            // The example transfer names a department by slug; the action's own
            // vocabulary is department_id — resolved here, at seed time.
            $definition['actions'] = array_map(function (array $action): array {
                if (($action['type'] ?? '') === 'transfer_department' && is_string($action['department_slug'] ?? null)) {
                    $action['department_id'] = DB::table('departments')->where('slug', $action['department_slug'])->value('id');
                    unset($action['department_slug']);
                }

                return $action;
            }, $definition['actions']);
            $this->rules->create(['name' => $name, ...$definition], $author);
            $rules++;
        }

        return ['forms' => $forms, 'rules' => $rules];
    }

    public function __construct(
        private readonly FormWriter $writer,
        private readonly FormPublisher $publisher,
        private readonly RuleWriter $rules,
    ) {}

    /** @return array<string, mixed> */
    private function bindings(string $departmentSlug): array
    {
        $departmentId = DB::table('departments')->where('slug', $departmentSlug)->value('id');

        return is_string($departmentId) ? ['department_id' => $departmentId] : [];
    }

    /** @return array<string, array{department: string, schema: array<string, mixed>}> name → seed */
    public static function forms(): array
    {
        $methodField = [
            'key' => 'method', 'type' => 'radio', 'required' => true,
            'label' => ['en' => 'Payment method', 'am' => 'የክፍያ መንገድ'],
            'options' => [
                ['value' => 'telebirr', 'label' => ['en' => 'telebirr', 'am' => 'ቴሌብር']],
                ['value' => 'cbe_birr', 'label' => ['en' => 'CBE Birr', 'am' => 'ሲቢኢ ብር']],
                ['value' => 'bank_transfer', 'label' => ['en' => 'Bank transfer', 'am' => 'የባንክ ዝውውር']],
            ],
        ];
        $amountFields = [
            ['key' => 'amount', 'type' => 'number', 'required' => true, 'label' => ['en' => 'Amount', 'am' => 'መጠን']],
            ['key' => 'currency', 'type' => 'dropdown', 'required' => true, 'label' => ['en' => 'Currency', 'am' => 'ገንዘብ'],
                'options' => [['value' => 'ETB', 'label' => ['en' => 'ETB (birr)', 'am' => 'ብር']]]],
        ];
        // FR-23: transaction reference with PER-METHOD regex hints — the hint keys
        // match the method option values; validation.regex is the fallback shape.
        $referenceField = [
            'key' => 'transaction_ref', 'type' => 'text', 'required' => true,
            'label' => ['en' => 'Transaction reference', 'am' => 'የግብይት ማጣቀሻ'],
            'help' => ['en' => 'The confirmation code from your payment provider.', 'am' => 'ከክፍያ አቅራቢዎ የመጣው የማረጋገጫ ኮድ።'],
            'validation' => ['regex' => '^[A-Za-z0-9-]{6,32}$', 'regex_hints' => [
                'telebirr' => '^[A-Z0-9]{10}$', 'cbe_birr' => '^CBE[0-9]{8,12}$', 'bank_transfer' => '^[A-Za-z0-9-]{6,32}$',
            ]],
        ];
        $timestampField = ['key' => 'happened_at', 'type' => 'time', 'required' => false, 'label' => ['en' => 'When did it happen?', 'am' => 'መቼ ተከሰተ?']];
        // Optional-but-requested (FR-23's own words): required=false, asked for in the help text.
        $screenshotField = [
            'key' => 'screenshot', 'type' => 'file', 'required' => false,
            'label' => ['en' => 'Screenshot', 'am' => 'ቅጽበታዊ ገጽ እይታ'],
            'help' => ['en' => 'A screenshot speeds things up a lot — please attach one if you can.', 'am' => 'ቅጽበታዊ ገጽ እይታ ሂደቱን በጣም ያፋጥናል — ከቻሉ ያያይዙ።'],
        ];
        $storyField = ['key' => 'details', 'type' => 'rich_text', 'required' => false, 'label' => ['en' => 'Anything else we should know?', 'am' => 'ሌላ ማወቅ ያለብን ነገር አለ?']];

        $paymentSchema = static fn (string $en, string $am, string $tag): array => [
            'languages' => ['en', 'am'],
            'steps' => [
                ['key' => 'payment', 'title' => ['en' => $en, 'am' => $am],
                    'fields' => [...$amountFields, $methodField, $referenceField, $timestampField]],
                ['key' => 'evidence', 'title' => ['en' => 'Anything that helps', 'am' => 'የሚረዳ ማንኛውም ነገር'],
                    'fields' => [$screenshotField, $storyField]],
            ],
            'completion' => ['route_tag' => $tag, 'tags' => [$tag]],
        ];

        return [
            'Deposit Issue' => ['department' => 'deposits', 'schema' => $paymentSchema('About your deposit', 'ስለ ተቀማጭዎ', 'deposit-issue')],
            'Withdrawal Issue' => ['department' => 'withdrawals', 'schema' => $paymentSchema('About your withdrawal', 'ስለ ወጪዎ', 'withdrawal-issue')],
            'Verification Request' => ['department' => 'general', 'schema' => [
                'languages' => ['en', 'am'],
                'steps' => [
                    ['key' => 'documents', 'title' => ['en' => 'Your documents', 'am' => 'ሰነዶችዎ'],
                        'fields' => [
                            ['key' => 'document_type', 'type' => 'radio', 'required' => true,
                                'label' => ['en' => 'Document type', 'am' => 'የሰነድ አይነት'],
                                'options' => [
                                    ['value' => 'national_id', 'label' => ['en' => 'National ID', 'am' => 'ብሔራዊ መታወቂያ']],
                                    ['value' => 'passport', 'label' => ['en' => 'Passport', 'am' => 'ፓስፖርት']],
                                    ['value' => 'drivers_license', 'label' => ['en' => "Driver's license", 'am' => 'የመንጃ ፈቃድ']],
                                ]],
                            ['key' => 'document_photo', 'type' => 'file', 'required' => false,
                                'label' => ['en' => 'Photo of the document', 'am' => 'የሰነዱ ፎቶ'],
                                'help' => ['en' => 'A clear photo of the whole document — please attach it here if you can.', 'am' => 'የሙሉ ሰነዱ ግልጽ ፎቶ — ከቻሉ እዚህ ያያይዙ።']],
                            $storyField,
                        ]],
                ],
                'completion' => ['route_tag' => 'verification', 'tags' => ['verification']],
            ]],
        ];
    }

    /** @return array<string, array{trigger: string, description: string, conditions?: array<string, mixed>, actions: list<array<string, mixed>>}> name → definition (all left inactive) */
    public static function exampleRules(): array
    {
        return [
            'Example: urgent keywords raise priority' => [
                'trigger' => 'message.received',
                'description' => 'When a customer message contains urgent words, raise the ticket to high. Edit the words, then activate.',
                'conditions' => ['all' => [['fact' => 'message.body', 'op' => 'contains', 'value' => 'urgent']]],
                'actions' => [['type' => 'set_priority', 'value' => 'high']],
            ],
            'Example: withdrawal form goes to Withdrawals' => [
                'trigger' => 'form.submitted',
                'description' => 'A submitted withdrawal form transfers the ticket to the Withdrawals department. Activate after reviewing.',
                'conditions' => ['all' => [['fact' => 'ticket.tags', 'op' => 'contains', 'value' => 'withdrawal-issue']]],
                'actions' => [['type' => 'transfer_department', 'department_slug' => 'withdrawals']],
            ],
            'Example: SLA breach adds a note' => [
                'trigger' => 'sla.breached',
                'description' => 'When an SLA stage breaches, drop an internal note so the timeline shows it was seen. Activate as-is or extend.',
                'actions' => [['type' => 'add_note', 'body' => 'SLA stage breached — automation noticed. Review this ticket.']],
            ],
        ];
    }
}
