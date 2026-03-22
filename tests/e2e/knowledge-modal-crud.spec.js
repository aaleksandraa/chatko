import { expect, test } from '@playwright/test';
import { loginAsDemoAdmin, openAdminView, uniqueSuffix } from './helpers/admin-ui';

test('knowledge modal edit/delete flow', async ({ page }) => {
    test.setTimeout(120000);

    const suffix = uniqueSuffix();
    const title = `E2E Knowledge ${suffix}`;
    const updatedTitle = `${title} Updated`;

    await loginAsDemoAdmin(page);
    await openAdminView(page, 'knowledge');

    await page.fill('#knowledge-title', title);
    await page.selectOption('#knowledge-type', 'faq');
    await page.selectOption('#knowledge-visibility', 'public');
    await page.fill('#knowledge-content', `Knowledge content ${suffix}`);
    await page.click('#knowledge-text-form button[type="submit"]');

    await expect(page.locator('#knowledge-table-body')).toContainText(title);

    const row = page.locator('#knowledge-table-body tr').filter({ hasText: title }).first();
    await expect(row).toBeVisible();

    const editButton = row.locator('button[data-action="edit"]').first();
    const docId = await editButton.getAttribute('data-id');
    expect(docId).toBeTruthy();

    await editButton.click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="title"]', updatedTitle);
    await page.selectOption('#entity-modal-fields [data-field-key="visibility"]', 'private');
    await page.click('#entity-modal-form button[type="submit"]');

    await expect(page.locator('#knowledge-table-body')).toContainText(updatedTitle);

    const updatedRow = page.locator('#knowledge-table-body tr').filter({ hasText: updatedTitle }).first();
    await updatedRow.locator('button[data-action="delete"]').click();
    await expect(page.locator('#confirm-modal')).toBeVisible();
    await page.click('#confirm-accept');

    await expect(page.locator(`#knowledge-table-body button[data-action="edit"][data-id="${docId}"]`)).toHaveCount(0);
});
