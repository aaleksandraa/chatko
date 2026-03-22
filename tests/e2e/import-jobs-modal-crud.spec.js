import { expect, test } from '@playwright/test';
import { getAdminApiHeaders, loginAsDemoAdmin, openAdminView, uniqueSuffix } from './helpers/admin-ui';

test('import jobs modal edit/delete flow', async ({ page }) => {
    test.setTimeout(120000);

    const suffix = uniqueSuffix();
    const integrationName = `E2E Import Source ${suffix}`;
    const logSummary = `E2E import summary ${suffix}`;

    await loginAsDemoAdmin(page);
    const headers = await getAdminApiHeaders(page);

    const integrationResponse = await page.request.post('/api/admin/integrations', {
        headers,
        data: {
            type: 'custom_api',
            name: integrationName,
            base_url: 'https://api.e2e.local',
        },
    });
    expect(integrationResponse.ok()).toBeTruthy();
    const integrationPayload = await integrationResponse.json();
    const integrationId = Number(integrationPayload?.data?.id);
    expect(Number.isFinite(integrationId)).toBeTruthy();

    const syncResponse = await page.request.post(`/api/admin/integrations/${integrationId}/sync`, {
        headers,
        data: {
            mode: 'initial',
        },
    });
    expect(syncResponse.ok()).toBeTruthy();
    const syncPayload = await syncResponse.json();
    const importJobId = String(syncPayload?.data?.id ?? '');
    expect(importJobId).not.toBe('');

    await openAdminView(page, 'imports');
    await page.click('#imports-load');
    await expect(page.locator(`#imports-table-body button[data-action="edit"][data-id="${importJobId}"]`)).toBeVisible();

    await page.locator(`#imports-table-body button[data-action="edit"][data-id="${importJobId}"]`).click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="status"]', 'completed');
    await page.fill('#entity-modal-fields [data-field-key="log_summary"]', logSummary);
    await page.click('#entity-modal-form button[type="submit"]');

    await expect(page.locator('#imports-table-body')).toContainText('completed');
    await expect(page.locator('#imports-table-body')).toContainText(logSummary);

    await page.locator(`#imports-table-body button[data-action="delete"][data-id="${importJobId}"]`).click();
    await expect(page.locator('#confirm-modal')).toBeVisible();
    await page.click('#confirm-accept');

    await expect(page.locator(`#imports-table-body button[data-action="edit"][data-id="${importJobId}"]`)).toHaveCount(0);
});
