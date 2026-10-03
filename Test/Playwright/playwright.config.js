// @ts-check
/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Playwright config for the BlueFairy Slack Live Chat storefront specs (M2MAP-141).
 * The Hyvä widget specs live beside this file, in the package (Test/ is export-ignored).
 *
 * BASE_URL IS REQUIRED and there is no default: a spec run against a host that
 * cannot contain the feature fails for reasons that have nothing to do with the
 * code (module-order-comments lost a day to a default host), and these specs WRITE
 * - they open conversations and post signed Slack events. Point them at a test rig
 * only, never a production store.
 *
 * Run (there is no node_modules here; borrow ~/Sites/blueSites/playwright's):
 *
 *   cd ~/Sites/blueSites/playwright && \
 *   NODE_PATH=$PWD/node_modules BASE_URL=https://<rig> \
 *     npx playwright test -c ~/Sites/Modules/module-slack-chat-hyva/Test/Playwright/playwright.config.js
 *
 * Environment:
 *   BASE_URL                  required - the Hyvä store view's base URL, no trailing slash
 *   PAGE_PATH                 optional - a page that shows the widget (default "/")
 *   SLACK_SIGNING_SECRET      the store's configured signing secret (agent-reply spec)
 *   DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME DB_TABLE_PREFIX
 *                             read-only lookup of the conversation's Slack thread
 *                             (agent-reply spec); uses the `mysql` client on PATH
 *   BFSLC_EXPECT_AWAY_MESSAGE the store's "away" message, set by the operator first
 *                             (availability spec; see that spec's header)
 * A spec whose inputs are missing SKIPS and says which input; it never passes.
 */
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.BASE_URL;
if (!baseURL) {
    throw new Error(
        'BASE_URL is not set. Point it at a TEST Magento store view running the Slack Live Chat widget, e.g.\n'
        + '  BASE_URL=https://hyvatest.example npx playwright test -c ~/Sites/Modules/module-slack-chat-hyva/Test/Playwright/playwright.config.js\n'
    );
}

module.exports = defineConfig({
    testDir: '.',
    testMatch: '**/*.spec.js',
    outputDir: './test-results',
    timeout: 120000,
    expect: { timeout: 15000 },
    // One worker: the base package rate-limits conversation starts per IP (10/min)
    // and every spec here starts one.
    workers: 1,
    fullyParallel: false,
    reporter: 'line',
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        acceptDownloads: true,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
