<?php

declare(strict_types=1);

namespace App\Domain\Kb\Telegram;

use App\Domain\Kb\Help\HelpContent;
use App\Domain\Kb\Tombstones\CitationUrls;

/**
 * Doc 10 FR-19 / BR-04 (S10 step 11) — the bot-menu RENDERING CONTRACT: a kb_category
 * target answers a NUMBERED option list; a kb_article target answers SUMMARY + LINK —
 * never the full text in chat (BR-04's word). Everything served is public + published
 * (BR-01 — HelpContent is the read model); language rides the BR-05 chain; an article
 * that stopped serving answers the tombstone key, never silence. Strings are i18n KEYS
 * (`kb.flow.*` — C-08): the RENDERING (Nutgram message assembly, keyboard building,
 * menu BINDING via the telegram_flow_targets CHECK) is Doc 05/12's — packet P7 files
 * the CHECK expansion; until it lands this contract has no Telegram caller ([D05]/[D12]).
 */
final class KbFlowRenderer
{
    public function __construct(private readonly HelpContent $help) {}

    /**
     * @param  list<string>  $chain
     * @return array{kind: string, title_key: string, title: mixed, options: list<array{n: int, title: string, article_id: string}>, empty_key: string|null}
     *
     * `title` is the category's per-language name jsonb, passed through untyped — the
     * renderer (Doc 05/12) picks the language.
     */
    public function forCategory(string $categoryId, array $chain): array
    {
        $tree = $this->help->tree();
        foreach ($tree['categories'] as $category) {
            if (($category['id'] ?? null) !== $categoryId) {
                continue;
            }
            $seen = [];
            $options = [];
            /** @var array{article_id: string, language: string, title: string} $item */
            foreach (is_array($category['articles'] ?? null) ? $category['articles'] : [] as $item) {
                if (isset($seen[$item['article_id']]) && ! in_array($item['language'], $chain, true)) {
                    continue; // one option per article; a chain language wins over the first-seen variant
                }
                $seen[$item['article_id']] = true;
                $options[$item['article_id']] = ['title' => $item['title'], 'article_id' => $item['article_id']];
            }
            $numbered = [];
            $n = 1;
            foreach (array_values($options) as $option) {
                $numbered[] = ['n' => $n++] + $option; // BR-04: numbered options — Telegram-first UX
            }

            return ['kind' => 'category', 'title_key' => 'kb.flow.category_title', 'title' => $category['name'], 'options' => $numbered, 'empty_key' => $numbered === [] ? 'kb.flow.category_empty' : null];
        }

        return ['kind' => 'category', 'title_key' => 'kb.flow.category_title', 'title' => null, 'options' => [], 'empty_key' => 'kb.flow.category_unknown'];
    }

    /**
     * @param  list<string>  $chain
     * @return array{kind: string, title: string|null, summary_key: string, summary: string|null, link: string|null, language: string|null, tombstone_key: string|null}
     */
    public function forArticle(string $articleId, array $chain): array
    {
        $article = $this->help->article($articleId, $chain);
        if ($article === null) {
            return ['kind' => 'article', 'title' => null, 'summary_key' => 'kb.flow.article_summary', 'summary' => null, 'link' => null, 'language' => null, 'tombstone_key' => 'kb.tombstone.retired'];
        }
        /** @var string $language */
        $language = $article['language'];
        /** @var string $slug */
        $slug = $article['slug'];
        /** @var string|null $summary */
        $summary = is_string($article['summary'] ?? null) ? $article['summary'] : $this->firstParagraph($article);

        return [
            'kind' => 'article',
            'title' => is_string($article['title'] ?? null) ? $article['title'] : null,
            'summary_key' => 'kb.flow.article_summary',
            'summary' => $summary, // BR-04: the summary and a link — the full text NEVER rides the chat
            'link' => CitationUrls::article($language, $slug),
            'language' => $language,
            'tombstone_key' => null,
        ];
    }

    /** @param array<string, mixed> $article */
    private function firstParagraph(array $article): ?string
    {
        foreach (is_array($article['blocks'] ?? null) ? $article['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'paragraph' && is_string($block['text'] ?? null)) {
                return mb_substr($block['text'], 0, 200);
            }
        }

        return null;
    }

    /**
     * Doc 15's deflection inputs need the view fact from the bot flow too — the binder (P7) calls this after a render.
     *
     * @param  list<string>  $chain
     */
    public function viewedVariantId(string $articleId, array $chain): ?string
    {
        $article = $this->help->article($articleId, $chain);

        return $article === null ? null : (is_string($article['variant_id'] ?? null) ? $article['variant_id'] : null);
    }
}
