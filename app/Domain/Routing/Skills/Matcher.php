<?php

declare(strict_types=1);

namespace App\Domain\Routing\Skills;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-11 / T-05 — skill matchers: DECLARATIVE config on the queue (skill_matchers, an
 * ORDERED list of {attribute, requirement}), evaluated in order over the ticket's attributes
 * into required skills; v1 skills = declared languages + department proficiencies on the
 * user profile (user_skills). A matcher: {"attribute": "language", "requirement": "match"}
 * → the agent must declare the ticket's language; {"attribute": "department", "requirement":
 * "proficient"} → the agent must list the queue's department. Unknown attributes are refused
 * at the writer (§8's matcher builder) — here they simply do not match.
 */
final class Matcher
{
    /**
     * @param  list<array<string, mixed>>  $matchers  each {attribute, requirement}; unknown shapes simply do not match
     * @param  array<string, mixed>  $ticket  language, department_id
     * @return array{language: ?string, department_id: ?string} the requirements (null = none)
     */
    public function requirements(array $matchers, array $ticket): array
    {
        $language = null;
        $department = null;
        foreach ($matchers as $m) {
            if (($m['attribute'] ?? null) === 'language' && ($m['requirement'] ?? null) === 'match' && is_string($ticket['language'] ?? null)) {
                $language = $ticket['language'];
            }
            if (($m['attribute'] ?? null) === 'department' && ($m['requirement'] ?? null) === 'proficient' && is_string($ticket['department_id'] ?? null)) {
                $department = $ticket['department_id'];
            }
        }

        return ['language' => $language, 'department_id' => $department];
    }

    /**
     * The agents among $userIds whose profile satisfies the requirements.
     *
     * @param  list<string>  $userIds
     * @param  array{language: ?string, department_id: ?string}  $requirements
     * @return list<string>
     */
    public function satisfying(array $userIds, array $requirements): array
    {
        if ($userIds === [] || ($requirements['language'] === null && $requirements['department_id'] === null)) {
            return $userIds;
        }
        $q = DB::table('user_skills')->whereIn('user_id', $userIds);
        if ($requirements['language'] !== null) {
            $q->whereRaw('? = ANY(languages)', [$requirements['language']]);
        }
        if ($requirements['department_id'] !== null) {
            $q->whereRaw('?::uuid = ANY(department_ids)', [$requirements['department_id']]);
        }
        /** @var list<string> $ok */
        $ok = $q->pluck('user_id')->all();

        return array_values(array_intersect($userIds, $ok));
    }
}
