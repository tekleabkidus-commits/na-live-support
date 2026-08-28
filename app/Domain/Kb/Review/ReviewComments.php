<?php

declare(strict_types=1);

namespace App\Domain\Kb\Review;

use App\Domain\Kb\Editor\InertContent;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/** Doc 10 FR-07 / §8 — the per-variant review thread: comments anchor to blocks, resolve-state tracked; text never edited after posting. */
final class ReviewComments
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit, private readonly InertContent $inert) {}

    public function add(string $variantId, ?string $versionId, ?string $blockAnchor, string $body, string $authorId): string
    {
        if (! DB::table('kb_article_variants')->where('id', $variantId)->exists()) {
            throw new InvalidArgumentException('unknown variant');
        }
        $id = (string) new UuidV7;
        DB::table('kb_review_comments')->insert([
            'id' => $id, 'tenant_id' => $this->context->tenantId(), 'variant_id' => $variantId, 'version_id' => $versionId,
            'block_anchor' => $blockAnchor, 'author_id' => $authorId, 'body' => $this->inert->sanitizeString($body),
        ]);
        $this->audit->record('kb.review.commented', 'kb_review_comment', $id, AuditActor::Staff, ['variant_id' => $variantId, 'block_anchor' => $blockAnchor], actorId: $authorId);

        return $id;
    }

    public function resolve(string $commentId, string $actorId): void
    {
        $updated = DB::update('UPDATE kb_review_comments SET resolved_at = clock_timestamp(), resolved_by = ?, updated_at = clock_timestamp() WHERE id = ? AND resolved_at IS NULL', [$actorId, $commentId]);
        if ($updated === 0) {
            throw new InvalidArgumentException('unknown or already resolved comment');
        }
        $this->audit->record('kb.review.resolved', 'kb_review_comment', $commentId, AuditActor::Staff, [], actorId: $actorId);
    }

    /** @return list<array<string, mixed>> in thread order (created_at — the DB stamp) */
    public function thread(string $variantId): array
    {
        $rows = DB::table('kb_review_comments')->where('variant_id', $variantId)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'version_id', 'block_anchor', 'author_id', 'body', DB::raw('resolved_at::text AS resolved_at'), 'resolved_by', DB::raw('created_at::text AS created_at')])->all();

        $out = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $entry */
            $entry = (array) $row;
            $out[] = $entry;
        }

        return $out;
    }

    public function openCount(string $variantId): int
    {
        return (int) DB::table('kb_review_comments')->where('variant_id', $variantId)->whereNull('resolved_at')->count();
    }
}
