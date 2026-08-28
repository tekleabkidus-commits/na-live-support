<?php

declare(strict_types=1);

namespace App\Domain\Kb\Workflow;

use App\Domain\Ai\Retrieval\KbIndexer;
use App\Domain\Kb\Review\ReviewComments;
use App\Domain\Kb\Sanitizer\PublishSanitizer;
use App\Domain\Kb\Versioning\Versions;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-07 / FR-13 / BR-01 / BR-02 / BR-06 / AC-01 / AC-10 — THE workflow door.
 * Draft → (submit) → In review → (approve: kb.articles.publish) → Published; reviewers
 * request changes (back to Draft, with a comment); direct publish from Draft is allowed
 * for a user holding edit+publish and is audited as SELF-PUBLISHED (the review step
 * auto-collapsed, never silent). Publish MINTS the immutable version (Versions::mint),
 * runs the sanitizer (flags → version; BR-06 override recorded ON the version), the
 * alt-text gate (AC-10: a PUBLIC publish with missing alts is refused and LISTS them),
 * flips the variant live for HUMANS immediately, marks retrieval pending for a public
 * variant in an ai_usable category (FR-13's split — the step-6 consumer swaps the
 * generation), and emits kb.article.published through the outbox in the SAME
 * transaction. Unpublish retires the chunks in-transaction (EC-06's flip).
 * This class is the ONLY writer of kb_article_variants.status (the Doc 06
 * status-writer class; WorkflowTest's sole-writer sweep proves it).
 */
final class Transitions
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly Versions $versions,
        private readonly PublishSanitizer $sanitizer,
        private readonly ReviewComments $comments,
        private readonly KbIndexer $indexer,
        private readonly SettingsRepository $settings,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    public function submit(string $variantId, string $actorId): void
    {
        DB::transaction(function () use ($variantId, $actorId): void {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['draft'], 'submit');
            DB::update('UPDATE kb_article_variants SET status = ?, submitted_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?', ['in_review', $variantId]);
            $this->audit->record('kb.variant.submitted', 'kb_article_variant', $variantId, AuditActor::Staff, ['old' => ['status' => 'draft'], 'new' => ['status' => 'in_review']], actorId: $actorId);
        });
    }

    public function requestChanges(string $variantId, string $actorId, string $comment, ?string $blockAnchor = null): void
    {
        DB::transaction(function () use ($variantId, $actorId, $comment, $blockAnchor): void {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['in_review'], 'request changes');
            $this->comments->add($variantId, $variant->current_version_id, $blockAnchor, $comment, $actorId);
            DB::update('UPDATE kb_article_variants SET status = ?, updated_at = clock_timestamp() WHERE id = ?', ['draft', $variantId]);
            $this->audit->record('kb.variant.changes_requested', 'kb_article_variant', $variantId, AuditActor::Staff, ['old' => ['status' => 'in_review'], 'new' => ['status' => 'draft']], actorId: $actorId);
        });
    }

    /**
     * Approve (from In review) or self-publish (from Draft — requires edit + publish on the
     * actor; the collapse is audited as such). Returns the minted version.
     *
     * @param  array{reason?: string}|null  $sanitizerOverride  BR-06: required when flags exist, recorded on the version
     * @return array{version_id: string, version: int, self_published: bool, flags: list<array{block: int, sentence: string, pattern: string}>, index: string}
     */
    public function publish(string $variantId, \App\Models\User $actor, ?string $note = null, ?array $sanitizerOverride = null): array
    {
        return DB::transaction(function () use ($variantId, $actor, $note, $sanitizerOverride): array {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['draft', 'in_review', 'published'], 'publish');
            $selfPublished = $variant->status === 'draft';
            if ($selfPublished && ! $this->verdicts->verdictFor($actor, 'kb.articles.edit')->allowed) {
                throw new TransitionRefused('direct publish from Draft needs edit + publish on the same person (FR-07); submit for review instead');
            }
            /** @var object{audience: string, category_id: string}|null $article */
            $article = DB::table('kb_articles')->where('id', $variant->article_id)->first(['audience', 'category_id']);
            if ($article === null) {
                throw new InvalidArgumentException('unknown article');
            }
            $decoded = json_decode($variant->working_copy, true);
            /** @var array<string, mixed> $content */
            $content = is_array($decoded) ? $decoded : [];

            // AC-10: the alt-text gate — a PUBLIC publish with missing alts is refused and lists them.
            if ($article->audience === 'public' && (bool) $this->settings->get('kb.require_alt')) {
                $missing = $this->missingAlts($content);
                if ($missing !== []) {
                    throw new TransitionRefused('public publish blocked: images without alt text (kb.require_alt)', ['missing_alt' => $missing]);
                }
            }

            // T-03/BR-06: flags surface to the publisher; an override is recorded ON the version.
            $flags = $this->sanitizer->scan($content, $variant->language);
            $override = null;
            if ($flags !== []) {
                if ($sanitizerOverride === null || trim((string) ($sanitizerOverride['reason'] ?? '')) === '') {
                    throw new TransitionRefused('sanitizer flags need keep/edit decisions — pass an override reason to publish as-is (BR-06)', ['flags' => $flags]);
                }
                /** @var object{t: string} $clock */
                $clock = DB::selectOne('SELECT clock_timestamp()::text AS t');
                $override = ['by' => $actor->identifier(), 'at' => $clock->t, 'reason' => (string) ($sanitizerOverride['reason'] ?? '')];
            }

            $minted = $this->versions->mint($variantId, $content, $actor->identifier(), $note, $flags, $override);
            $aiUsable = (bool) DB::table('kb_categories')->where('id', $article->category_id)->value('ai_usable');
            $indexStatus = $article->audience === 'public' && $aiUsable ? 'pending' : ($article->audience === 'internal' ? 'pending' : 'none');
            DB::update(
                'UPDATE kb_article_variants SET status = ?, current_version_id = ?, translation_state = ?, index_status = ?, published_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?',
                ['published', $minted['version_id'], 'current', $indexStatus, $variantId],
            );

            $action = $selfPublished ? 'kb.variant.self_published' : 'kb.variant.published';
            $this->audit->record($action, 'kb_article_variant', $variantId, AuditActor::Staff, ['version_id' => $minted['version_id'], 'version' => $minted['version'], 'old' => ['status' => $variant->status], 'new' => ['status' => 'published'], 'flags' => count($flags), 'override' => $override !== null], actorId: $actor->identifier());
            if ($override !== null) {
                $this->audit->record('kb.sanitizer.overridden', 'kb_article_version', $minted['version_id'], AuditActor::Staff, ['flags' => count($flags), 'reason' => $override['reason']], actorId: $actor->identifier());
            }
            $this->events->publish('kb.article.published', [
                'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variantId,
                'version_id' => $minted['version_id'], 'language' => $variant->language, 'audience' => $article->audience, 'ai_usable' => $aiUsable,
            ]);

            return ['version_id' => $minted['version_id'], 'version' => $minted['version'], 'self_published' => $selfPublished, 'flags' => $flags, 'index' => $indexStatus];
        });
    }

    /** Published → Draft; the chunks retire IN THIS TRANSACTION (EC-06's flip, never a delete). */
    public function unpublish(string $variantId, string $actorId): void
    {
        DB::transaction(function () use ($variantId, $actorId): void {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['published'], 'unpublish');
            $this->indexer->retireVariant($variantId);
            DB::update('UPDATE kb_article_variants SET status = ?, index_status = ?, updated_at = clock_timestamp() WHERE id = ?', ['draft', 'none', $variantId]);
            $this->audit->record('kb.variant.unpublished', 'kb_article_variant', $variantId, AuditActor::Staff, ['old' => ['status' => 'published'], 'new' => ['status' => 'draft']], actorId: $actorId);
        });
    }

    /** FR-09 (step 8 wires archive/un-archive fully): the matrix edge lives here so the status writer stays ONE class. */
    public function archive(string $variantId, string $actorId): void
    {
        DB::transaction(function () use ($variantId, $actorId): void {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['published', 'draft', 'in_review'], 'archive');
            $this->indexer->retireVariant($variantId);
            DB::update('UPDATE kb_article_variants SET status = ?, index_status = ?, updated_at = clock_timestamp() WHERE id = ?', ['archived', 'none', $variantId]);
            $this->audit->record('kb.variant.archived', 'kb_article_variant', $variantId, AuditActor::Staff, ['old' => ['status' => $variant->status], 'new' => ['status' => 'archived']], actorId: $actorId);
        });
    }

    public function unarchive(string $variantId, string $actorId): void
    {
        DB::transaction(function () use ($variantId, $actorId): void {
            $variant = $this->lock($variantId);
            $this->expect($variant->status, ['archived'], 'un-archive');
            DB::update('UPDATE kb_article_variants SET status = ?, updated_at = clock_timestamp() WHERE id = ?', ['draft', $variantId]);
            $this->audit->record('kb.variant.unarchived', 'kb_article_variant', $variantId, AuditActor::Staff, ['old' => ['status' => 'archived'], 'new' => ['status' => 'draft']], actorId: $actorId);
        });
    }

    /** The SAME class flips every variant of an article into review — Visibility's EC-11 path calls this inside its transaction. */
    public function forceReviewForArticle(string $articleId, string $actorId, string $reason): int
    {
        /** @var list<string> $ids */
        $ids = DB::table('kb_article_variants')->where('article_id', $articleId)->whereIn('status', ['published', 'draft'])->pluck('id')->all();
        foreach ($ids as $id) {
            $this->indexer->retireVariant($id);
            DB::update('UPDATE kb_article_variants SET status = ?, index_status = ?, submitted_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?', ['in_review', 'none', $id]);
            $this->audit->record('kb.variant.submitted', 'kb_article_variant', $id, AuditActor::Staff, ['old' => ['status' => 'published|draft'], 'new' => ['status' => 'in_review'], 'reason' => $reason], actorId: $actorId);
        }

        return count($ids);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string> block indexes/ids of images without alt text
     */
    private function missingAlts(array $content): array
    {
        $missing = [];
        foreach (array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : []) as $index => $block) {
            $alt = is_array($block) ? ($block['alt'] ?? '') : '';
            if (is_array($block) && ($block['type'] ?? null) === 'image' && (! is_string($alt) || trim($alt) === '')) {
                $id = $block['id'] ?? null;
                $missing[] = is_string($id) ? $id : 'block:'.$index;
            }
        }

        return $missing;
    }

    /** @return object{id: string, article_id: string, language: string, status: string, working_copy: string, current_version_id: string|null} */
    private function lock(string $variantId): object
    {
        /** @var object{id: string, article_id: string, language: string, status: string, working_copy: string, current_version_id: string|null}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->lockForUpdate()->first(['id', 'article_id', 'language', 'status', 'working_copy', 'current_version_id']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }

        return $variant;
    }

    /** @param list<string> $allowed */
    private function expect(string $status, array $allowed, string $transition): void
    {
        if (! in_array($status, $allowed, true)) {
            throw new TransitionRefused(sprintf('cannot %s from %s (FR-07)', $transition, $status), ['status' => $status]);
        }
    }
}
