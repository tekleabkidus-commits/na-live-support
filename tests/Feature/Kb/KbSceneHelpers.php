<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Shared scene builders for the Doc 10 suites (S10 step 3+). Actors carry exactly the
 * CLOSED kb.* keys the test names (+ tickets.view, the staff floor); the scene creates
 * the tenant, binds it, and returns ids from the REAL doors so every later assertion
 * walks the same path an operator would.
 */
function kbActor(array $keys, string $label = 'kb'): User
{
    return grantPermissions(User::factory()->create(), array_merge(['tickets.view'], $keys), 'S10 '.$label.' '.implode(',', $keys));
}

/** @return array{tenant: mixed, tenant_id: string, admin: User, category_id: string} */
function kbScene(bool $aiUsable = true): array
{
    $tenant = createTenant();
    activateTenant($tenant); // the staff chain's tenant.state middleware refuses a provisioning tenant
    $tenantId = bindTenant($tenant);
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish', 'kb.view_internal', 'kb.feedback.view'], 'admin');
    $category = test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Payments', 'am' => 'ክፍያዎች'], 'slug' => 'payments', 'ai_usable' => $aiUsable]);
    $category->assertStatus(201);
    bindTenant($tenant);

    return ['tenant' => $tenant, 'tenant_id' => $tenantId, 'admin' => $admin, 'category_id' => $category->json('id')];
}

/** An article with one paragraph in its working copy, via the real doors. @return array{article_id: string, variant_id: string} */
function kbArticle(array $s, string $title = '[FIXTURE] Deposits', string $audience = 'public'): array
{
    $created = test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => $audience, 'source_language' => 'en', 'title' => $title]);
    $created->assertStatus(201);
    bindTenant($s['tenant']);
    test()->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$created->json('variant_id').'/working-copy', ['content' => ['blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => '[FIXTURE] Deposits with telebirr settle within 30 minutes.']]]])->assertOk();
    bindTenant($s['tenant']);

    return ['article_id' => $created->json('article_id'), 'variant_id' => $created->json('variant_id')];
}
