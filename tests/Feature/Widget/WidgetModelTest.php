<?php

declare(strict_types=1);

use App\Models\Widget;

/**
 * P-WIDGET (Doc 02 `app/Models/Widget.php`, countersigned 2026-09-04) — the
 * fail-first control: SCHEMA AMENDMENT 7's columns exist, but the Doc 02
 * model's fillable list does not name them, so a mass-assigned `name` is
 * silently DISCARDED (Laravel's default) and never lands. RED before the
 * packet; green after. Doc 02's own widget suites stay byte-untouched.
 */
it('P-WIDGET: the Designer\'s columns are mass-assignable on the Widget model and land (SCHEMA AMENDMENT 7 item 1)', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);

    $widget->update(['name' => 'Support subdomain widget', 'bundle_channel' => 'early', 'test_mode' => true]);
    $fresh = Widget::query()->findOrFail($widget->getKey());

    expect($fresh->name)->toBe('Support subdomain widget', 'name was silently discarded — the fillable list lacks the amendment-7 columns')
        ->and($fresh->bundle_channel)->toBe('early')
        ->and($fresh->test_mode)->toBeTrue();
    // Doc 02's half untouched: allowed_origins still round-trips as an array.
    expect($fresh->allowed_origins)->toBe([WIDGET_ORIGIN]);
});
