<?php

declare(strict_types=1);

namespace App\Domain\Kb\Visibility;

use App\Domain\Kb\Workflow\Transitions;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-10 / EC-11 / SEC-04 / SCHEMA AMENDMENT 4 FLAG A — THE ONE DOOR that writes
 * kb_articles.audience. A visibility change is a PUBLISH, not a toggle: it is audited
 * HIGH-VISIBILITY (old/new + actor — it changes the leak surface) and takes the FULL
 * pipeline including review — every published/draft variant drops to In review with
 * its chunks retired; nothing serves under the new audience until re-published.
 * VisibilityTest's sole-writer sweep proves no other file writes the column
 * (sabotage #12: a bare UPDATE elsewhere goes red on the sweep AND on the audit).
 */
final class Visibility
{
    public function __construct(private readonly AuditLogger $audit, private readonly Transitions $transitions) {}

    /** @return array{old: string, new: string, variants_to_review: int} */
    public function change(string $articleId, string $audience, string $actorId): array
    {
        if (! in_array($audience, ['public', 'internal'], true)) {
            throw new InvalidArgumentException('audience is public or internal');
        }

        return DB::transaction(function () use ($articleId, $audience, $actorId): array {
            /** @var object{audience: string}|null $article */
            $article = DB::table('kb_articles')->where('id', $articleId)->whereNull('deleted_at')->lockForUpdate()->first(['audience']);
            if ($article === null) {
                throw new InvalidArgumentException('unknown article');
            }
            if ($article->audience === $audience) {
                return ['old' => $audience, 'new' => $audience, 'variants_to_review' => 0];
            }
            DB::table('kb_articles')->where('id', $articleId)->update(['audience' => $audience, 'updated_at' => DB::raw('clock_timestamp()')]);
            $count = $this->transitions->forceReviewForArticle($articleId, $actorId, 'visibility_changed');
            $this->audit->record('kb.article.visibility_changed', 'kb_article', $articleId, AuditActor::Staff, ['old' => ['audience' => $article->audience], 'new' => ['audience' => $audience], 'variants_to_review' => $count], actorId: $actorId);

            return ['old' => $article->audience, 'new' => $audience, 'variants_to_review' => $count];
        });
    }
}
