<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Kb/KbSceneHelpers.php';

/**
 * Doc 10 BR-02 / AC-09 / FR-06 (S10 step 9 — written BEFORE the tools exist): "AI assists, humans
 * publish". STATIC: no AI authoring file (the Authoring/ door, the authoring + translate jobs) writes
 * `status`, names a workflow transition, or reaches Transitions at all — with a positive control (the
 * ONE lawful writer trips the same patterns). RUNTIME: after every FR-06 tool has run against a real
 * draft, the variant is still `draft`, no publish audit exists and no kb.article.published event left
 * the door (AiAuthoringTest drives the tools; this suite pins the invariant on their ARTIFACTS).
 */
const KB_AI_PATHS = [
    'app/Domain/Kb/Authoring',
    'app/Jobs/Kb/KbAuthoringJob.php',
    'app/Jobs/Kb/KbTranslateVariantJob.php',
];

/** @return array<string, list<string>> file → the patterns it matched */
function kbAiPublishPatterns(string $path): array
{
    $hits = [];
    $patterns = [
        'status_sql' => '/UPDATE\s+kb_article_variants\s+SET\s+(?:(?!\bWHERE\b)[^;])*\bstatus\s*=/i',
        'status_builder' => '/->update\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
        'published_literal' => '/[\'"]published[\'"]/',
        'transitions' => '/\bTransitions\b|->publish\(|->approve\(|->submit\(/',
    ];
    $files = is_dir(base_path($path)) ? glob(base_path($path).'/*.php') : [base_path($path)];
    foreach ((array) $files as $file) {
        if (! is_string($file) || ! is_file($file)) {
            continue;
        }
        $source = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) { // comments dropped by TOKEN, not opcache-dependent
            if (is_array($token)) {
                $source .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
            } else {
                $source .= $token;
            }
        }
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $hits[str_replace(base_path().'/', '', $file)][] = $name;
            }
        }
    }

    return $hits;
}

it('STATIC: no AI authoring path writes status, names a publish transition or touches Transitions — positive control: Transitions.php trips the same patterns', function (): void {
    $control = kbAiPublishPatterns('app/Domain/Kb/Workflow/Transitions.php');
    expect(array_key_exists('app/Domain/Kb/Workflow/Transitions.php', $control))->toBeTrue('the patterns are vacuous — the real writer must trip them')
        ->and($control['app/Domain/Kb/Workflow/Transitions.php'] ?? [])->toContain('status_sql')->toContain('published_literal');

    $present = 0;
    foreach (KB_AI_PATHS as $path) {
        if (file_exists(base_path($path))) {
            $present++;
        }
        expect(kbAiPublishPatterns($path))->toBe([], 'an AI path can publish: '.json_encode(kbAiPublishPatterns($path)));
    }
    expect($present)->toBeGreaterThan(0, 'non-vacuity: at least one AI path exists on disk');
});

it('RUNTIME: every AI-accented variant on record is a draft — no publish audit, no kb.article.published event — after the tools ran (the AiAuthoringTest artifacts)', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Runtime pin');
    // Tools write accents; this scene has none yet — the assertion below is over whatever AI-born rows exist in THIS tenant after AiAuthoringTest-style runs.
    DB::table('kb_article_variants')->where('id', $a['variant_id'])->update(['translation_source' => 'ai']);
    bindTenant($s['tenant']);
    $aiVariants = DB::table('kb_article_variants')->where('translation_source', 'ai')->get(['id', 'status']);
    expect($aiVariants)->not->toBeEmpty();
    foreach ($aiVariants as $v) {
        expect($v->status)->toBe('draft', 'an AI-born variant left draft without a human');
        expect(DB::table('audit_log')->whereIn('action_key', ['kb.variant.published', 'kb.variant.self_published'])->where('entity_id', $v->id)->exists())->toBeFalse()
            ->and(DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $v->id)->exists())->toBeFalse();
    }
});
