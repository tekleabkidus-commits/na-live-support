// Session 0 Part G — placeholder page loads and is axe-clean.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('placeholder page renders via Inertia and reports the app booted', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('placeholder-heading')).toBeVisible();
    await expect(page.getByTestId('boot-status')).toHaveText('app booted');
});

test('placeholder page has no axe-core violations (WCAG 2.2 AA baseline)', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('placeholder-heading')).toBeVisible();

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
});
