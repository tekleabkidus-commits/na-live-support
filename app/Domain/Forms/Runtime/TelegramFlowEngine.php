<?php

declare(strict_types=1);

namespace App\Domain\Forms\Runtime;

use App\Domain\Forms\ConditionEval;
use App\Domain\Forms\VersionReader;
use App\Support\Cache\TenantCache;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-21 / T-07 / EC-06 / SS-03 / FS-04 — the Telegram conversational
 * renderer of the ONE versioned contract (closes Doc 10 FR-19's tap-engine
 * half: a `form` flow target mounts here). One field per message; choice
 * fields render inline buttons (direct mode) or numbered text (business
 * mode); validation errors are friendly retries with 3-attempts-then-offer-
 * human; file fields accept native photo/document refs; `/human` at any point
 * hands off with partial data flagged incomplete (SS-03).
 *
 * State: Redis working copy (TenantCache, 24 h TTL — never the truth) + the
 * form_flow_checkpoints row UPDATED AT STEP BOUNDARIES (RIDER 3). A dead
 * Redis resumes from the row with a recap prompt (FS-04). The version is
 * pinned at start and immutable by grant — a mid-form publish never moves a
 * running flow (EC-06).
 *
 * The engine EMITS data (prompts, products); it never sends, never creates
 * tickets — completion and handoff products go through their owning doors
 * (SubmissionWriter, Doc 09's handoff packet) by the caller.
 */
final class TelegramFlowEngine
{
    private const TTL_SECONDS = 24 * 3600;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantCache $cache,
        private readonly VersionReader $versions,
    ) {}

    /** @return array<string, mixed> the first prompt */
    public function start(string $conversationId, string $formId, string $mode, ?string $customerLanguage): array
    {
        $versionId = DB::table('forms')->where('id', $formId)->where('active', true)->value('current_version_id');
        if (! is_string($versionId)) {
            throw new DomainException('form_not_published');
        }
        $contract = $this->versions->widgetContract($versionId);
        $language = WidgetContract::resolveLanguage($contract['schema'], $customerLanguage);

        $checkpointId = (string) Str::uuid7();
        DB::transaction(function () use ($checkpointId, $conversationId, $formId, $versionId, $language, $mode): void {
            // A second start supersedes the first honestly — by outcome, never delete.
            DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->whereNull('outcome')
                ->update(['outcome' => 'superseded', 'finished_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            DB::table('form_flow_checkpoints')->insert([
                'id' => $checkpointId, 'tenant_id' => $this->context->tenantId(),
                'conversation_id' => $conversationId, 'form_id' => $formId, 'version_id' => $versionId,
                'step_index' => 0, 'answers' => '{}', 'language' => $language, 'mode' => $mode,
            ]);
        });
        $state = [
            'checkpoint_id' => $checkpointId, 'form_id' => $formId, 'version_id' => $versionId,
            'mode' => $mode, 'language' => $language, 'answers' => [], 'attempts' => [],
        ];
        $this->putState($conversationId, $state);

        return $this->promptFor($state, $this->nextField($contract['schema'], []));
    }

    /**
     * @param  array{text?: string, choice?: string, file?: string}  $input
     * @return array<string, mixed> prompt | retry | offer_human | handed_off | completed | resumed
     */
    public function handle(string $conversationId, array $input): array
    {
        $state = $this->cache->get('flow:'.$conversationId);
        if (! is_array($state)) {
            // FS-04: Redis died — resume from the LAST DB CHECKPOINT with a recap.
            $state = $this->rebuildFromCheckpoint($conversationId);
            if ($state === null) {
                return ['kind' => 'no_flow'];
            }
            $this->putState($conversationId, $state);
            $schema = $this->versions->widgetContract($state['version_id'])['schema'];

            return ['kind' => 'resumed', 'recap_key' => 'workflow.flow.resumed_recap',
                'kept' => count($state['answers']),
            ] + $this->promptFor($state, $this->nextField($schema, $state['answers']));
        }
        /** @var array{checkpoint_id: string, form_id: string, version_id: string, mode: string, language: string, answers: array<string, mixed>, attempts: array<string, int>} $state */
        $schema = $this->versions->widgetContract($state['version_id'])['schema'];

        if (($input['text'] ?? null) === '/human') {
            return $this->handOff($conversationId, $state);
        }

        $slot = $this->nextField($schema, $state['answers']);
        if ($slot === null) {
            return $this->finish($conversationId, $state, $schema);
        }
        [$stepIndex, $field] = $slot;
        $key = ConditionEval::keyOf($field) ?? '';
        $value = $this->extract($field, $input, $state['mode']);
        if ($value === null) {
            $state['attempts'][$key] = ($state['attempts'][$key] ?? 0) + 1;
            $this->putState($conversationId, $state);
            if ($state['attempts'][$key] >= self::MAX_ATTEMPTS) {
                // FR-21: three friendly retries, then offer a human.
                return ['kind' => 'offer_human', 'text_key' => 'workflow.flow.offer_human']
                    + $this->promptFor($state, $slot);
            }

            return ['kind' => 'retry', 'text_key' => 'workflow.flow.retry'] + $this->promptFor($state, $slot);
        }

        $state['answers'][$key] = $value;
        unset($state['attempts'][$key]);
        $next = $this->nextField($schema, $state['answers']);
        if ($next === null || $next[0] > $stepIndex) {
            // T-07: DB checkpoint at STEP BOUNDARIES only.
            DB::table('form_flow_checkpoints')->where('id', $state['checkpoint_id'])->update([
                'step_index' => $next[0] ?? $stepIndex + 1,
                'answers' => json_encode($state['answers'], JSON_THROW_ON_ERROR),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
        }
        $this->putState($conversationId, $state);

        return $next === null ? $this->finish($conversationId, $state, $schema) : $this->promptFor($state, $next);
    }

    /** SS-03: `/human` (or an accepted offer) — partial data, flagged incomplete, for the packet.
     * @param  array{checkpoint_id: string, form_id: string, version_id: string, language: string, answers: array<string, mixed>}  $state
     * @return array<string, mixed> */
    public function handOff(string $conversationId, array $state): array
    {
        $this->close($conversationId, $state['checkpoint_id'], 'handed_off', $state['answers']);

        return ['kind' => 'handed_off', 'form_data' => [
            'form_id' => $state['form_id'], 'version_id' => $state['version_id'],
            'answers' => $state['answers'], 'complete' => false,
        ]];
    }

    /** @param array{checkpoint_id: string, form_id: string, version_id: string, answers: array<string, mixed>} $state
     * @param array<string, mixed> $schema
     * @return array<string, mixed> */
    private function finish(string $conversationId, array $state, array $schema): array
    {
        $this->close($conversationId, $state['checkpoint_id'], 'completed', $state['answers']);

        return ['kind' => 'completed', 'form_data' => [
            'form_id' => $state['form_id'], 'version_id' => $state['version_id'],
            'answers' => $state['answers'], 'complete' => true,
        ], 'completion' => is_array($schema['completion'] ?? null) ? $schema['completion'] : []];
    }

    /** @param array<string, mixed> $answers */
    private function close(string $conversationId, string $checkpointId, string $outcome, array $answers): void
    {
        DB::table('form_flow_checkpoints')->where('id', $checkpointId)->update([
            'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
            'outcome' => $outcome, 'finished_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $this->cache->forget('flow:'.$conversationId);
    }

    /** @return array{checkpoint_id: string, form_id: string, version_id: string, mode: string, language: string, answers: array<string, mixed>, attempts: array<string, int>}|null */
    private function rebuildFromCheckpoint(string $conversationId): ?array
    {
        /** @var object{id: string, form_id: string, version_id: string, mode: string, language: string, answers: string}|null $row */
        $row = DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->whereNull('outcome')
            ->first(['id', 'form_id', 'version_id', 'mode', 'language', 'answers']);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $answers */
        $answers = json_decode($row->answers, true, 16, JSON_THROW_ON_ERROR);

        return [
            'checkpoint_id' => $row->id, 'form_id' => $row->form_id, 'version_id' => $row->version_id,
            'mode' => $row->mode, 'language' => $row->language, 'answers' => $answers, 'attempts' => [],
        ];
    }

    /** @param array<string, mixed> $state */
    private function putState(string $conversationId, array $state): void
    {
        $this->cache->put('flow:'.$conversationId, $state, self::TTL_SECONDS, 'form.submitted');
    }

    /** The next unanswered VISIBLE field, in step order — one field per message.
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array{0: int, 1: array<mixed>}|null */
    private function nextField(array $schema, array $answers): ?array
    {
        foreach (is_array($schema['steps'] ?? null) ? array_values($schema['steps']) : [] as $stepIndex => $step) {
            if (! is_array($step) || ! is_array($step['fields'] ?? null)) {
                continue;
            }
            if (is_array($step['visible_when'] ?? null) && ! ConditionEval::holds($step['visible_when'], $answers)) {
                continue;
            }
            foreach ($step['fields'] as $field) {
                if (! is_array($field) || ! ConditionEval::visible($field, $answers)) {
                    continue;
                }
                $key = ConditionEval::keyOf($field);
                if ($key !== null && ! array_key_exists($key, $answers)) {
                    // Optional fields still get ASKED (skip records an explicit '').
                    return [$stepIndex, $field];
                }
            }
        }

        return null;
    }

    /** The render contract for one field: FR-21's mode split is DATA.
     * @param  array{mode: string, language: string}  $state
     * @param  array{0: int, 1: array<mixed>}|null  $slot
     * @return array<string, mixed> */
    private function promptFor(array $state, ?array $slot): array
    {
        if ($slot === null) {
            return ['kind' => 'prompt', 'field' => null];
        }
        $field = $slot[1];
        $language = $state['language'];
        $label = is_array($field['label'] ?? null) ? ($field['label'][$language] ?? null) : null;
        $help = is_array($field['help'] ?? null) ? ($field['help'][$language] ?? null) : null;
        $prompt = [
            'kind' => 'prompt', 'field' => ConditionEval::keyOf($field),
            'type' => is_string($field['type'] ?? null) ? $field['type'] : 'text',
            'text' => is_string($label) ? $label : (string) (ConditionEval::keyOf($field) ?? ''),
            'help' => is_string($help) ? $help : null,
            'required' => ($field['required'] ?? false) === true,
        ];
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        if ($options !== []) {
            $choices = [];
            foreach ($options as $option) {
                if (is_array($option) && is_string($option['value'] ?? null)) {
                    $optionLabel = is_array($option['label'] ?? null) ? ($option['label'][$language] ?? null) : null;
                    $choices[] = ['value' => $option['value'], 'label' => is_string($optionLabel) ? $optionLabel : $option['value']];
                }
            }
            if ($state['mode'] === 'direct') {
                $prompt['buttons'] = $choices;         // inline buttons
            } else {
                // Business mode: numbered text — the customer replies with a number.
                $prompt['numbered'] = array_map(
                    static fn (int $i, array $choice): string => ($i + 1).'. '.$choice['label'],
                    array_keys($choices), $choices,
                );
                $prompt['choices'] = $choices;
                $prompt['instruction_key'] = 'workflow.flow.choose_number';
            }
        }

        return $prompt;
    }

    /** Validate + normalize one answer; null = invalid (drives the friendly retry).
     * @param  array<mixed>  $field
     * @param  array{text?: string, choice?: string, file?: string}  $input */
    private function extract(array $field, array $input, string $mode): ?string
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        if ($type === 'file') {
            // Native photo/document: the opaque media ref (Doc 04's A-26 shape).
            if (is_string($input['file'] ?? null) && $input['file'] !== '') {
                return $input['file'];
            }

            // Optional file may be skipped with an empty text.
            return ($field['required'] ?? false) !== true && ($input['text'] ?? null) === '' ? '' : null;
        }
        if ($options !== []) {
            $values = [];
            foreach ($options as $option) {
                if (is_array($option) && is_string($option['value'] ?? null)) {
                    $values[] = $option['value'];
                }
            }
            $picked = $input['choice'] ?? null;
            if ($picked === null && $mode === 'business' && is_string($input['text'] ?? null)) {
                // Numbered-text mode: "2" picks the second option.
                $index = (int) trim($input['text']) - 1;
                $picked = $values[$index] ?? null;
            }

            return is_string($picked) && in_array($picked, $values, true) ? $picked : null;
        }
        $text = is_string($input['text'] ?? null) ? trim($input['text']) : null;
        if ($text === null || ($text === '' && ($field['required'] ?? false) === true)) {
            return null;
        }
        if ($text === '') {
            return '';      // an optional field skipped with an empty reply
        }
        $valid = match ($type) {
            'number' => is_numeric($text),
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => preg_match('/^\+?[0-9][0-9 \-()]{5,20}$/', $text) === 1,
            'url' => filter_var($text, FILTER_VALIDATE_URL) !== false,
            default => true,
        };
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $regex = is_string($validation['regex'] ?? null) ? $validation['regex'] : null;
        if ($valid && $regex !== null) {
            $valid = preg_match('/'.str_replace('/', '\/', $regex).'/u', $text) === 1;
        }

        return $valid ? $text : null;
    }
}
