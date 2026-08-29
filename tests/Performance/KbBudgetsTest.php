<?php

declare(strict_types=1);

use App\Domain\Kb\Search\KbSearchDocuments;
use App\Domain\Kb\Search\StaffSearch;

require_once __DIR__.'/../Feature/Kb/KbSceneHelpers.php';

/**
 * Doc 10 NFR-03, DEV-HARDWARE-INDICATIVE (the pre-agreed [D19] bucket owns the 5k-article proof):
 * staff search ≤ 120 ms p95 measured here over 200 indexed variants and 20 queries on a laptop
 * container — a necessary condition, never the sufficient one. The widget leg (≤ 200 ms, CDN-cached
 * tree) is [D18]/[D13].
 */
it('§12 legs, dev-indicative (step 13): publish ≤ 30 s and the block-aware diff ≤ 500 ms at dev scale — the 50k-chunk and L2 legs stay [D19]', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Perf article');
    $transitions = app(App\Domain\Kb\Workflow\Transitions::class);
    $admin = $s['admin'];

    // Publish, measured over three mints (a re-publish from published is the FR-07 edge).
    $publish = measure(function () use ($transitions, $a, $admin): void {
        $transitions->publish($a['variant_id'], $admin);
    }, 3);
    assertBudget('kb publish door (§12 ≤30 s)', $publish, 30000.0);

    // The block-aware diff between the first two minted versions.
    /** @var list<object{id: string}> $versions */
    $versions = Illuminate\Support\Facades\DB::table('kb_article_versions')->where('variant_id', $a['variant_id'])->orderBy('version')->limit(2)->get(['id'])->all();
    $versionsApi = app(App\Domain\Kb\Versioning\Versions::class);
    $diff = measure(function () use ($versionsApi, $versions): void {
        $versionsApi->diffBetween($versions[0]->id, $versions[1]->id);
    }, 10);
    assertBudget('kb block diff (§12 ≤500 ms)', $diff, 500.0);
});

it('meets NFR-03 indicatively: staff search ≤120 ms p95 at 200 indexed variants (Doc 19 owns 5k)', function (): void {
    $s = kbScene();
    $docs = app(KbSearchDocuments::class);
    $index = app(App\Domain\Kb\Search\KbSearchIndex::class)->ensure();
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $rows[] = ['id' => 'perf-'.$i, 'article_id' => 'perf-a-'.$i, 'language' => 'en', 'visibility' => 'public', 'status' => 'published', 'category_id' => $s['category_id'],
            'title' => '[FIXTURE] Article '.$i.' about '.['deposits', 'withdrawals', 'bonuses', 'verification'][$i % 4], 'summary' => '[FIXTURE] summary '.$i, 'slug' => 'perf-'.$i, 'version_id' => null,
            'body' => '[FIXTURE] body text number '.$i.' telebirr cbe birr transfer', 'tags' => ['perf']];
    }
    $task = $index->addDocuments($rows);
    app(App\Domain\Kb\Search\KbSearchIndex::class)->client()->waitForTask($task['taskUid']);
    $search = app(StaffSearch::class);
    $viewer = kbActor([], 'perf');
    $samples = [];
    foreach (['deposits', 'withdrawals', 'bonuses', 'verification', 'telebirr'] as $q) {
        for ($k = 0; $k < 4; $k++) {
            $t = hrtime(true);
            $search->search($q, $viewer);
            $samples[] = (hrtime(true) - $t) / 1e6;
        }
    }
    sort($samples);
    $p95 = $samples[(int) floor(0.95 * (count($samples) - 1))];
    expect($p95)->toBeLessThanOrEqual(120.0, sprintf('staff search p95 %.1f ms (dev-indicative)', $p95));
});
