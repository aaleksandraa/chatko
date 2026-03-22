import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8001',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    webServer: {
        command: 'node -e "try{require(\'fs\').unlinkSync(\'public/hot\')}catch(e){}" && npm run build && php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=127.0.0.1 --port=8001',
        url: process.env.E2E_BASE_URL || 'http://127.0.0.1:8001',
        reuseExistingServer: !process.env.CI,
        timeout: 180000,
    },
});
