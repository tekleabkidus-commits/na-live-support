<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Skills\Matcher;
use App\Domain\Routing\Skills\TicketLanguage;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-11 / T-05 / EC-04 / BR-01 — SKILL-BASED: ordered matchers → required skills;
 * filter the routable set to skill-matching agents, then LOAD-BASED among them. No match →
 * the queue's fallback: `nearest_skill` = drop the language requirement (department
 * proficiency kept), `wait` = hold for a matching agent (the dispatcher raises
 * routing.no_skill_match at the threshold — step 6's consumer). EC-04: a STARVED ticket
 * (the cap, evaluated by PositionInQueue and handed in as `starved`) drops the language
 * requirement in BOTH modes — BR-01 over matching; the record shows both facts.
 */
final class SkillBased implements AssignmentStrategy
{
    public function __construct(
        private readonly Matcher $matcher,
        private readonly TicketLanguage $language,
        private readonly LoadBased $loadBased,
    ) {}

    public function name(): string
    {
        return 'skill_based';
    }

    public function order(string $queueId, array $routable, array $ticket): array
    {
        /** @var object{skill_matchers: string, skill_fallback: string}|null $queue */
        $queue = DB::table('queues')->where('id', $queueId)->first(['skill_matchers', 'skill_fallback']);
        /** @var list<array<string, mixed>> $matchers */
        $matchers = $queue === null ? [] : array_values((array) json_decode($queue->skill_matchers, true));
        $fallback = $queue === null ? 'nearest_skill' : $queue->skill_fallback;
        $starved = (bool) ($ticket['starved'] ?? false);

        $language = is_string($ticket['language'] ?? null) ? $ticket['language'] : (isset($ticket['conversation_id']) && is_string($ticket['conversation_id']) ? $this->language->forConversation($ticket['conversation_id']) : null);
        $requirements = $this->matcher->requirements($matchers, ['language' => $language, 'department_id' => $ticket['department_id'] ?? null]);
        $ids = array_column($routable, 'user_id');
        $matching = $this->matcher->satisfying($ids, $requirements);

        $note = sprintf('required %s', json_encode(array_filter($requirements), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        // §14 slots: the language CODE (the reader localizes it via ref.language) + counts. Never ids.
        $slots = ['language' => $requirements['language'], 'online' => count($ids)];
        $code = 'strategy.skill.matched';
        if ($matching === [] && $requirements['language'] !== null) {
            if ($starved) {
                // EC-04: the cap fired — the language requirement drops (BR-01 over matching).
                $matching = $this->matcher->satisfying($ids, ['language' => null, 'department_id' => $requirements['department_id']]);
                $note .= ' — no language match; STARVED past the cap → language requirement DROPPED (EC-04/BR-01)';
                $code = 'strategy.skill.dropped_starved';
            } elseif ($fallback === 'nearest_skill') {
                $matching = $this->matcher->satisfying($ids, ['language' => null, 'department_id' => $requirements['department_id']]);
                $note .= ' — no language match; fallback nearest_skill: language requirement dropped';
                $code = 'strategy.skill.dropped_nearest';
            } else {
                $note .= sprintf(' — %d online, 0 with the skill → fallback wait: HOLD for a matching agent', count($ids));

                return ['candidates' => [], 'note' => $note, 'note_code' => 'strategy.skill.hold', 'note_slots' => $slots];
            }
        }
        if ($matching === []) {
            return ['candidates' => [], 'note' => $note.' — nobody satisfies the requirements', 'note_code' => 'strategy.skill.none', 'note_slots' => $slots];
        }
        $subset = array_values(array_filter($routable, static fn (array $r): bool => in_array($r['user_id'], $matching, true)));
        $ordered = $this->loadBased->order($queueId, $subset, $ticket);

        $out = ['candidates' => $ordered['candidates'], 'note' => $note.'; then '.$ordered['note'], 'facts' => $ordered['facts'] ?? [], 'note_code' => $code, 'note_slots' => $slots];
        if (isset($ordered['note_code'])) {
            $out['then_code'] = $ordered['note_code'];
            $out['then_slots'] = $ordered['note_slots'] ?? [];
        }

        return $out;
    }
}
