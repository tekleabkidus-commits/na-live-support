<?php

declare(strict_types=1);

namespace App\Domain\Kb\Seeding;

use App\Domain\Kb\Articles\ArticleWriter;
use App\Domain\Kb\Categories\CategoryTree;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-04 / BR-07 / §14 (S10 step 8; A-45 AS RULED) — the starter set: five DRAFT
 * articles in the tenant default language inside a seeded "Getting started" category —
 * CONTENT, not config (the deliberate contrast with Doc 03's roles): editable, DELETABLE.
 * The RoleSeeder fingerprint contract verbatim: a re-seed touches a starter ONLY while its
 * working copy still hashes to its seeded fingerprint; an operator-edited starter is never
 * overwritten; an operator-DELETED starter stays deleted (soft-deleted rows count as
 * existing). Idempotent under retry/concurrency (the unique slug + variant arbiters).
 */
final class KnowledgeBaseSeeder
{
    /** @return array<string, array{title: array<string, string>, body: array<string, string>}> slug → content */
    public static function starters(): array
    {
        return [
            'deposit-faq' => [
                'title' => ['en' => 'Deposit FAQ', 'am' => 'የተቀማጭ ጥያቄዎች'],
                'body' => ['en' => 'How to deposit, supported methods (telebirr, CBE Birr, bank transfer), settlement times, and what to do when a deposit is delayed.', 'am' => 'እንዴት እንደሚያስገቡ፣ የሚደገፉ መንገዶች (ቴሌብር፣ ሲቢኢ ብር፣ የባንክ ዝውውር)፣ የመድረሻ ጊዜዎች እና ተቀማጭ ሲዘገይ ምን ማድረግ እንዳለብዎ።'],
            ],
            'withdrawal-faq' => [
                'title' => ['en' => 'Withdrawal FAQ', 'am' => 'የወጪ ጥያቄዎች'],
                'body' => ['en' => 'How withdrawals work, processing times per method, common reasons a withdrawal is pending, and how to cancel one.', 'am' => 'ወጪዎች እንዴት እንደሚሠሩ፣ በየመንገዱ የማስኬጃ ጊዜዎች፣ ወጪ የሚዘገይባቸው የተለመዱ ምክንያቶች እና እንዴት መሰረዝ እንደሚቻል።'],
            ],
            'verification-guide' => [
                'title' => ['en' => 'Verification guide', 'am' => 'የማረጋገጫ መመሪያ'],
                'body' => ['en' => 'Which documents are accepted, how to submit them, and how long review takes.', 'am' => 'የትኞቹ ሰነዶች እንደሚቀበሉ፣ እንዴት እንደሚያቀርቡ እና ግምገማ ምን ያህል እንደሚፈጅ።'],
            ],
            'bonus-rules' => [
                'title' => ['en' => 'Bonus rules skeleton', 'am' => 'የጉርሻ ሕጎች ረቂቅ'],
                'body' => ['en' => 'Wagering requirements, qualifying bets, expiry windows, and how bonus balances pay out. Replace with your promotion terms.', 'am' => 'የውርርድ መስፈርቶች፣ ብቁ ውርርዶች፣ የማብቂያ ጊዜዎች እና የጉርሻ ሒሳብ አከፋፈል። በእርስዎ የማስተዋወቂያ ውሎች ይተኩ።'],
            ],
            'responsible-play' => [
                'title' => ['en' => 'Responsible play', 'am' => 'ኃላፊነት የተሞላበት ጨዋታ'],
                'body' => ['en' => 'Deposit limits, self-exclusion, and where to find free, confidential support. Keep this article current — it is linked from safety surfaces.', 'am' => 'የተቀማጭ ገደቦች፣ ራስን ማገድ እና ነፃ፣ ሚስጥራዊ ድጋፍ የት እንደሚገኝ። ይህ ጽሑፍ ወቅታዊ ይሁን — ከደህንነት ገጾች ይገናኛል።'],
            ],
        ];
    }

    public function __construct(
        private readonly CategoryTree $categories,
        private readonly ArticleWriter $articles,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return int how many starters were created or healed */
    public function seed(): int
    {
        $languageRaw = $this->settings->get('tenant.default_language');
        $language = is_string($languageRaw) && $languageRaw !== '' ? $languageRaw : 'en';
        $categoryId = $this->categoryId($language);
        $actor = '00000000-0000-0000-0000-000000000000'; // the provisioning system actor (AuditActor::System on the door would need a staff id; seeded rows carry the system uuid)
        $touched = 0;
        foreach (self::starters() as $slug => $starter) {
            $title = $starter['title'][$language] ?? $starter['title']['en'];
            $body = $starter['body'][$language] ?? $starter['body']['en'];
            $fingerprint = self::fingerprint($title, $body);
            /** @var object{id: string, seeded_fingerprint: string|null, deleted_at: string|null}|null $existing */
            $existing = DB::table('kb_articles')->where('seeded', true)->where('seeded_fingerprint', 'like', $slug.':%')->first(['id', 'seeded_fingerprint', 'deleted_at']);
            if ($existing !== null) {
                if ($existing->deleted_at !== null) {
                    continue; // BR-07: an operator-DELETED starter stays deleted
                }
                if ($existing->seeded_fingerprint !== $slug.':'.$fingerprint) {
                    continue; // release drift heals only untouched starters; content changes ship as new fingerprints deliberately
                }
                /** @var object{id: string, working_copy: string}|null $variant */
                $variant = DB::table('kb_article_variants')->where('article_id', $existing->id)->where('language', $language)->first(['id', 'working_copy']);
                if ($variant !== null && $variant->working_copy !== $this->copyJson($body)) {
                    continue; // operator-edited — never overwritten
                }
                if ($variant !== null) {
                    continue; // healthy and untouched — nothing to do
                }
            }
            $created = $this->articles->create($categoryId, 'public', $language, $title, $actor, tags: ['starter'], seeded: true, seededFingerprint: $slug.':'.$fingerprint);
            DB::update('UPDATE kb_article_variants SET working_copy = ?::jsonb, working_copy_saved_at = clock_timestamp() WHERE id = ?', [$this->copyJson($body), $created['variant_id']]);
            $touched++;
        }

        return $touched;
    }

    public static function fingerprint(string $title, string $body): string
    {
        return hash('sha256', $title.'|'.$body);
    }

    private function copyJson(string $body): string
    {
        return json_encode(['blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $body]]], JSON_THROW_ON_ERROR);
    }

    private function categoryId(string $language): string
    {
        $existing = DB::table('kb_categories')->where('slug', 'getting-started')->whereNull('deleted_at')->value('id');
        if (is_string($existing)) {
            return $existing;
        }

        return $this->categories->create(['en' => 'Getting started', 'am' => 'መጀመሪያ'], 'getting-started', null, false, 'public', '00000000-0000-0000-0000-000000000000');
    }
}
