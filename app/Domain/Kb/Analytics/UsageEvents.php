<?php

declare(strict_types=1);

namespace App\Domain\Kb\Analytics;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-12 (S10 step 10): the analytics feed's producer for kb.article.viewed. A view is a
 * RENDER of a published variant on a surface; conversation_id (nullable) is the key Doc 15's
 * deflection formula joins on ("a view/citation followed by no ticket in that conversation within
 * 24 h") — the FORMULA is Doc 15's ([D15]); this module produces the facts. cited_by_ai and
 * inserted_by_agent are produced at their doors (step 7b).
 */
final class UsageEvents
{
    public const SURFACES = ['widget', 'telegram', 'staff', 'copilot'];

    public function __construct(private readonly TenantContext $context, private readonly EventPublisher $events) {}

    public function viewed(string $variantId, string $surface, ?string $conversationId = null): void
    {
        if (! in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException('unknown surface');
        }
        /** @var object{id: string, article_id: string, language: string, current_version_id: string|null, status: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'language', 'current_version_id', 'status']);
        if ($variant === null || $variant->status !== 'published' || $variant->current_version_id === null) {
            return; // only a published render is a view (BR-01)
        }
        $this->events->publish('kb.article.viewed', [
            'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variant->id,
            'version_id' => $variant->current_version_id, 'language' => $variant->language, 'surface' => $surface, 'conversation_id' => $conversationId,
        ]);
    }
}
