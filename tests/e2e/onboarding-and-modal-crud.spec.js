import { expect, test } from '@playwright/test';
import { loginAsDemoAdmin, openAdminView, uniqueSuffix } from './helpers/admin-ui';

test('onboarding wizard and modal CRUD click flows', async ({ page }) => {
    test.setTimeout(120000);

    const suffix = uniqueSuffix();
    const tenantName = `E2E Tenant ${suffix}`;
    const tenantSlug = `e2e-tenant-${suffix}`;
    const ownerName = `E2E Owner ${suffix}`;
    const ownerEmail = `owner-${suffix}@e2e.local`;
    const ownerPassword = 'password123';

    const widgetName = `E2E Widget ${suffix}`;
    const widgetNameUpdated = `${widgetName} Updated`;
    const integrationName = `E2E Source ${suffix}`;
    const integrationNameUpdated = `${integrationName} Updated`;
    const productName = `E2E Product ${suffix}`;
    const productNameUpdated = `${productName} Updated`;

    await loginAsDemoAdmin(page);
    await openAdminView(page, 'onboarding');
    await expect(page.getByRole('heading', { name: 'Tenant Onboarding Wizard' })).toBeVisible();

    await page.fill('#onboarding-tenant-name', tenantName);
    await page.fill('#onboarding-tenant-slug', tenantSlug);
    await page.fill('#onboarding-owner-name', ownerName);
    await page.fill('#onboarding-owner-email', ownerEmail);
    await page.fill('#onboarding-owner-password', ownerPassword);
    await page.fill('#onboarding-owner-password-confirmation', ownerPassword);
    await page.click('#onboarding-next');
    await page.fill('#onboarding-widget-name', widgetName);
    await page.fill('#onboarding-widget-locale', 'bs');
    await page.fill('#onboarding-widget-domains', '["http://localhost:3000"]');
    await page.fill('#onboarding-widget-theme', '{"primary_color":"#005f73"}');
    await page.click('#onboarding-next');
    await page.fill('#onboarding-ai-provider', 'openai');
    await page.fill('#onboarding-ai-model', 'gpt-5-mini');
    await page.fill('#onboarding-ai-embedding', 'text-embedding-3-small');
    await page.fill('#onboarding-ai-temperature', '0.3');
    await page.fill('#onboarding-ai-max-tokens', '700');
    await page.click('#onboarding-submit');

    await expect(page.locator('#global-alert')).toContainText('Tenant kreiran', { timeout: 30000 });
    await expect(page.locator('#session-status')).toContainText('tenant: demo-shop');

    await page.click('button[data-view="integrations"]');
    await page.fill('#integration-name', integrationName);
    await page.selectOption('#integration-type', 'manual');
    await page.click('#integration-create-form button[type="submit"]');
    await page.click('#integrations-load');

    const integrationRow = page.locator('#integrations-table-body tr').filter({ hasText: integrationName }).first();
    await expect(integrationRow).toBeVisible();
    await integrationRow.locator('button[data-action="edit"]').click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="name"]', integrationNameUpdated);
    await page.click('#entity-modal-form button[type="submit"]');
    await expect(page.locator('#global-alert')).toContainText('Uspjesno sacuvano');
    await expect(page.locator('#integrations-table-body')).toContainText(integrationNameUpdated);

    await page.click('button[data-view="products"]');
    await page.fill('#product-name', productName);
    await page.fill('#product-sku', `SKU-${suffix}`);
    await page.fill('#product-price', '39.90');
    await page.fill('#product-category', 'e2e');
    await page.fill('#product-brand', 'e2e-brand');
    await page.click('#product-create-form button[type="submit"]');
    await expect(page.locator('#products-table-body')).toContainText(productName);

    const productRow = page.locator('#products-table-body tr').filter({ hasText: productName }).first();
    await productRow.locator('button[data-action="edit"]').click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="name"]', productNameUpdated);
    await page.fill('#entity-modal-fields [data-field-key="price"]', '49.90');
    await page.click('#entity-modal-form button[type="submit"]');
    await expect(page.locator('#products-table-body')).toContainText(productNameUpdated);

    await page.click('button[data-view="widgets"]');
    await page.fill('#widget-create-name', widgetName);
    await page.fill('#widget-create-locale', 'bs');
    await page.click('#widget-create-form button[type="submit"]');
    await page.click('#widgets-load');

    const widgetRow = page.locator('#widgets-table-body tr').filter({ hasText: widgetName }).first();
    await expect(widgetRow).toBeVisible();
    await widgetRow.locator('button[data-action="edit"]').click();
    await expect(page.locator('#entity-modal')).toBeVisible();
    await page.fill('#entity-modal-fields [data-field-key="name"]', widgetNameUpdated);
    await page.click('#entity-modal-form button[type="submit"]');
    await expect(page.locator('#widgets-table-body')).toContainText(widgetNameUpdated);

    const widgetUpdatedRow = page.locator('#widgets-table-body tr').filter({ hasText: widgetNameUpdated }).first();
    await widgetUpdatedRow.locator('button[data-action="delete"]').click();
    await expect(page.locator('#confirm-modal')).toBeVisible();
    await page.click('#confirm-accept');
    await expect(page.locator('#widgets-table-body')).not.toContainText(widgetNameUpdated);
});
