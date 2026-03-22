import { expect, test } from '@playwright/test';
import { getAdminApiHeaders, loginAsDemoAdmin, openAdminView, uniqueSuffix } from './helpers/admin-ui';

test('conversations messages + modal edit/delete flow', async ({ page }) => {
    test.setTimeout(120000);

    const suffix = uniqueSuffix();
    const messageText = `E2E message ${suffix}`;

    await loginAsDemoAdmin(page);
    const headers = await getAdminApiHeaders(page);

    const widgetsResponse = await page.request.get('/api/admin/widgets', { headers });
    expect(widgetsResponse.ok()).toBeTruthy();
    const widgetsPayload = await widgetsResponse.json();
    const widgets = Array.isArray(widgetsPayload?.data) ? widgetsPayload.data : [];
    expect(widgets.length).toBeGreaterThan(0);
    const publicKey = String(widgets[0]?.public_key ?? '');
    expect(publicKey).toMatch(/^wpk_/);

    const startResponse = await page.request.post('/api/widget/session/start', {
        data: {
            public_key: publicKey,
            source_url: 'https://e2e.local/product-page',
        },
    });
    expect(startResponse.ok()).toBeTruthy();
    const startPayload = await startResponse.json();
    const conversationId = String(startPayload?.data?.conversation_id ?? '');
    const sessionId = String(startPayload?.data?.session_id ?? '');
    const visitorUuid = String(startPayload?.data?.visitor_uuid ?? '');
    const sessionToken = String(startPayload?.data?.widget_session_token ?? '');
    expect(conversationId).not.toBe('');
    expect(sessionToken).not.toBe('');

    const messageResponse = await page.request.post('/api/widget/message', {
        data: {
            public_key: publicKey,
            conversation_id: Number(conversationId),
            session_id: sessionId,
            visitor_uuid: visitorUuid,
            widget_session_token: sessionToken,
            source_url: 'https://e2e.local/product-page',
            message: messageText,
        },
    });
    expect(messageResponse.ok()).toBeTruthy();

    await openAdminView(page, 'conversations');
    await page.click('#conversations-load');
    await expect(page.locator(`#conversations-table-body button[data-action="messages"][data-id="${conversationId}"]`)).toBeVisible();

    await page.locator(`#conversations-table-body button[data-action="messages"][data-id="${conversationId}"]`).click();
    await expect(page.locator('#conversation-messages')).toContainText(messageText);

    await page.locator(`#conversations-table-body button[data-action="edit"][data-id="${conversationId}"]`).click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="status"]', 'resolved');
    await page.check('#entity-modal-fields [data-field-key="lead_captured"]');
    await page.click('#entity-modal-form button[type="submit"]');
    await expect(page.locator('#conversations-table-body')).toContainText('resolved');

    await page.locator(`#conversations-table-body button[data-action="delete"][data-id="${conversationId}"]`).click();
    await expect(page.locator('#confirm-modal')).toBeVisible();
    await page.click('#confirm-accept');

    await expect(page.locator(`#conversations-table-body button[data-action="edit"][data-id="${conversationId}"]`)).toHaveCount(0);
});
