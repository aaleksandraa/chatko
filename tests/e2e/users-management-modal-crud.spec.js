import { expect, test } from '@playwright/test';
import { loginAsDemoAdmin, openAdminView, uniqueSuffix } from './helpers/admin-ui';

test('users management create/edit/delete flow', async ({ page }) => {
    test.setTimeout(120000);

    const suffix = uniqueSuffix();
    const email = `e2e-user-${suffix}@test.local`;
    const updatedName = `E2E User ${suffix} Updated`;
    const password = 'password123';

    await loginAsDemoAdmin(page);
    await openAdminView(page, 'users');

    await page.fill('#user-name', `E2E User ${suffix}`);
    await page.fill('#user-email', email);
    await page.selectOption('#user-role', 'support');
    await page.fill('#user-password', password);
    await page.fill('#user-password-confirmation', password);
    await page.click('#user-create-form button[type="submit"]');

    await expect(page.locator('#users-table-body')).toContainText(email);

    const row = page.locator('#users-table-body tr').filter({ hasText: email }).first();
    await expect(row).toBeVisible();
    const userId = await row.locator('button[data-action="edit"]').first().getAttribute('data-id');
    expect(userId).toBeTruthy();

    await row.locator('button[data-action="edit"]').click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="name"]', updatedName);
    await page.selectOption('#entity-modal-fields [data-field-key="role"]', 'editor');
    await page.click('#entity-modal-form button[type="submit"]');

    await expect(page.locator('#users-table-body')).toContainText(updatedName);
    await expect(page.locator('#users-table-body')).toContainText('editor');

    await page.locator(`#users-table-body button[data-action="reset_password"][data-id="${userId}"]`).click();
    await expect(page.locator('#global-alert')).toContainText('Password reset email poslan');

    await page.locator(`#users-table-body button[data-action="delete"][data-id="${userId}"]`).click();
    await expect(page.locator('#confirm-modal')).toBeVisible();
    await page.click('#confirm-accept');

    await expect(page.locator(`#users-table-body button[data-action="edit"][data-id="${userId}"]`)).toHaveCount(0);
});
