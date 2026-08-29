<?php

declare(strict_types=1);

namespace App\Domain\Kb\Feedback;

use App\Domain\Kb\Editor\InertContent;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-11 / SEC-05 / EC-10 / FR-12 (S10 step 10) — the ONE writer of kb_feedback.
 * Customer scores (widget/telegram; the visitor is a HASH, never an identity — N-06) are
 * DEDUPLICATED by the DB arbiter (tenant, variant, visitor_hash): one score per visitor per
 * article, latest wins — an UPDATE on the existing row, never a second row. Comments are
 * hostile input: length-capped (kb.feedback.comment_max_chars) and stored INERT
 * (InertContent::sanitizeString) so no staff view can be handed live markup. Every landed
 * score emits kb.feedback.received (identifiers + categories; the comment never rides the
 * bus). Staff internal notes (FR-11) are the same table with staff_id set — no score
 * dedupe, no event (a note is not a customer signal). Only published variants take
 * customer feedback (BR-01).
 */
final class FeedbackWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VisitorLimiter $limiter,
        private readonly InertContent $inert,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $source  the surface the caller IS (each door passes its own name) — data,
     *                          never branched on here (N-20); the kb_feedback_source_check CHECK
     *                          constraint is the enforcement, so garbage refuses by physics (23514)
     * @return array{feedback_id: string, score: int, replaced: bool}
     */
    public function customer(string $variantId, string $visitorHash, int $score, ?string $comment, string $source): array
    {
        if (! in_array($score, [-1, 1], true)) {
            throw new InvalidArgumentException('score is -1 or 1');
        }
        $comment = $this->cleanComment($comment);
        if (! $this->limiter->allow($visitorHash)) {
            throw new FeedbackRefused('rate_limited', 'feedback rate limit reached for this visitor');
        }
        /** @var object{id: string, article_id: string, status: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'status']);
        if ($variant === null || $variant->status !== 'published') {
            throw new InvalidArgumentException('unknown or unpublished variant');
        }

        return DB::transaction(function () use ($variant, $visitorHash, $score, $comment, $source): array {
            /** @var object{id: string}|null $existing */
            $existing = DB::table('kb_feedback')->where('variant_id', $variant->id)->where('visitor_hash', $visitorHash)->lockForUpdate()->first(['id']);
            if ($existing !== null) {
                // EC-10: latest wins — the arbiter makes a second row impossible; this is the only other path.
                // source is NOT updatable: the column grant (APP_UPDATE_COLUMNS) omits it, so the surface
                // of record is the first vote's — immutable by physics, like the insert's CHECK.
                DB::table('kb_feedback')->where('id', $existing->id)->update(['score' => $score, 'comment' => $comment, 'updated_at' => DB::raw('clock_timestamp()')]);
                $id = $existing->id;
            } else {
                $id = (string) new UuidV7;
                DB::table('kb_feedback')->insert([
                    'id' => $id, 'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variant->id,
                    'source' => $source, 'visitor_hash' => $visitorHash, 'staff_id' => null, 'score' => $score, 'comment' => $comment,
                ]);
            }
            $this->events->publish('kb.feedback.received', [
                'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variant->id,
                'feedback_id' => $id, 'source' => $source, 'score' => $score, 'has_comment' => $comment !== null,
            ]);

            return ['feedback_id' => $id, 'score' => $score, 'replaced' => $existing !== null];
        });
    }

    /**
     * FR-11: a staff member's internal note on any variant (any status).
     *
     * @return array{feedback_id: string}
     */
    public function staffNote(string $variantId, string $staffId, string $comment): array
    {
        $clean = $this->cleanComment($comment);
        if ($clean === null) {
            throw new InvalidArgumentException('a note needs text');
        }
        /** @var object{id: string, article_id: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        $id = (string) new UuidV7;
        DB::transaction(function () use ($id, $variant, $staffId, $clean): void {
            DB::table('kb_feedback')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variant->id,
                'source' => 'staff', 'visitor_hash' => null, 'staff_id' => $staffId, 'score' => 1, 'comment' => $clean,
            ]);
            $this->audit->record('kb.feedback.noted', 'kb_feedback', $id, AuditActor::Staff, ['variant_id' => $variant->id], actorId: $staffId);
        });

        return ['feedback_id' => $id];
    }

    /** §7: mark a feedback item actioned (kb.feedback.view). */
    public function action(string $feedbackId, string $staffId): void
    {
        /** @var object{id: string, variant_id: string, actioned_at: string|null}|null $row */
        $row = DB::table('kb_feedback')->where('id', $feedbackId)->first(['id', 'variant_id', 'actioned_at']);
        if ($row === null) {
            throw new InvalidArgumentException('unknown feedback');
        }
        DB::transaction(function () use ($row, $staffId): void {
            DB::table('kb_feedback')->where('id', $row->id)->update(['actioned_at' => DB::raw('clock_timestamp()'), 'actioned_by' => $staffId, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('kb.feedback.actioned', 'kb_feedback', $row->id, AuditActor::Staff, ['variant_id' => $row->variant_id], actorId: $staffId);
        });
    }

    private function cleanComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $comment = trim($comment);
        if ($comment === '') {
            return null;
        }
        if (mb_strlen($comment) > $this->limiter->commentCap()) {
            throw new FeedbackRefused('too_long', 'comment exceeds kb.feedback.comment_max_chars');
        }

        return $this->inert->sanitizeString($comment);
    }
}
