import { expect } from '@playwright/test';

export function uniqueSuffix() {
    return `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
}

export async function loginAsDemoAdmin(page) {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Chatko Control Room' })).toBeVisible();

    await page.fill('#login-email', 'system@demo.local');
    await page.fill('#login-password', 'password123');
    await page.click('#login-form button[type="submit"]');

    await expect(page.locator('#session-status')).toContainText('tenant: demo-shop', { timeout: 30000 });
}

export async function openAdminView(page, view) {
    await page.click(`button[data-view="${view}"]`);
    await expect(page.locator(`#view-${view}`)).toHaveClass(/active/);
}

export async function getAdminApiHeaders(page) {
    const session = await page.evaluate(() => {
        const raw = window.localStorage.getItem('chatko_admin_session_v1');
        if (!raw) {
            return { token: null, tenantSlug: null };
        }

        try {
            const parsed = JSON.parse(raw);
            return {
                token: parsed?.token ?? null,
                tenantSlug: parsed?.tenantSlug ?? null,
            };
        } catch {
            return { token: null, tenantSlug: null };
        }
    });

    expect(session.token).toBeTruthy();
    expect(session.tenantSlug).toBeTruthy();

    return {
        Accept: 'application/json',
        Authorization: `Bearer ${session.token}`,
        'Content-Type': 'application/json',
        'X-Tenant-Slug': session.tenantSlug,
    };
}
