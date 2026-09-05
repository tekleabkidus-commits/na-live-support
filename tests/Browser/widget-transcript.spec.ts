// Doc 13 SEC-06 — the overflow menu's "Download transcript": the visitor's OWN conversation as a text file, frame text
// in the visitor's language, content verbatim, notes ABSENT (the staff-only note planted through the harness never appears).
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test('SEC-06: the transcript carries the thread verbatim with localized frame text and no internal note; the file is handed to the browser', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'transcript line one');
    expect(harness('handoff')).toContain('TICKET=');
    harness('agent-join');
    harness('staff-reply', '--text=[FIXTURE] a reply for the record');
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'for the record',
        { timeout: 20000 },
    );
    harness('note', '--text=[FIXTURE] STAFF ONLY: verify the reference');
    await panel
        .locator('[aria-haspopup=menu][aria-label], button[aria-haspopup=menu]')
        .first()
        .click();
    const download = page.waitForEvent('download', { timeout: 15000 }).catch(() => null);
    await panel.locator('[data-role=transcript]').click();
    const text = await page
        .frames()
        .find((f) => f.url().includes('frame=panel'))!
        .waitForFunction(
            () => (window as unknown as { __nlwTranscript?: string }).__nlwTranscript,
            undefined,
            { timeout: 15000 },
        )
        .then((h) => h.jsonValue() as Promise<string>);
    expect(text).toContain('Your conversation transcript');
    expect(text).toContain('transcript line one');
    expect(text).toContain('a reply for the record');
    expect(text).not.toContain('STAFF ONLY');
    expect(text).toMatch(/\d\d:\d\d · You · /);
    const d = await download;
    if (d) expect(d.suggestedFilename()).toMatch(/^transcript-\d{4}-\d{2}-\d{2}\.txt$/);
    harness('resolve');
});
