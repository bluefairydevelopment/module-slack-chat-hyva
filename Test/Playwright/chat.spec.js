/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * M2MAP-141 - the Hyvä Slack Live Chat widget, end to end, on a Hyvä store view.
 *
 * Preconditions (operator, before the run):
 *   - BlueFairy_SlackChat + BlueFairy_SlackChatHyva enabled; the theme CSS rebuilt;
 *   - "Show the Chat Widget" = Yes and Slack credentials configured for the store view;
 *   - file uploads enabled with image/png allowed and a limit of at least 3 MB
 *     (bluefairy_slack_chat/file_uploads/max_bytes >= 3145728) - else the upload spec skips;
 *   - for the agent-reply spec: a BOT TOKEN (webhook-only posts have no thread), the
 *     store's signing secret in SLACK_SIGNING_SECRET, and read access to the database.
 *
 * WHY THE AGENT REPLY READS THE DATABASE. An agent's reply reaches the store as a Slack
 * event naming the conversation's channel and thread_ts. The browser never learns the
 * thread_ts (it is Slack's, not the visitor's), the REST API rightly does not expose it,
 * and adding a test-only route to the module to reveal it would ship a hole. So the test
 * reads it from bfslc_sessions - read-only - and skips, saying why, when it cannot.
 */
const { test, expect } = require('@playwright/test');
const h = require('./helpers');

test.describe('Hyvä Slack Live Chat widget', () => {
    test('is lazy: no chat request until the bubble is opened, then a message is sent and accepted', async ({ page }) => {
        const chat = h.recordChatRequests(page);
        await page.goto(h.pagePath());
        const config = await h.bootConfig(page);
        expect(config.restRoot).toMatch(/\/rest\/[^/]+\/V1\/bluefairy-slack-chat\/$/);

        await page.waitForLoadState('networkidle');
        expect(chat, 'the widget made a request before the visitor opened it').toEqual([]);

        await h.openChat(page);
        await page.locator(h.SEL.name).fill('E2E Visitor');
        const text = 'e2e hello ' + Date.now();
        const sent = await h.sendMessage(page, text);
        expect(sent.direction).toBe('outbound');
        expect(sent.body).toBe(text);

        const token = await h.storedToken(page, config.storeCode);
        expect(token).toMatch(/^[a-f0-9]{64}$/);
        // The reply stream is opened once a conversation exists.
        await expect.poll(() => chat.some((r) => r.includes('/bluefairy_slc/stream/index'))).toBe(true);
    });

    test('an agent reply posted to Slack reaches the open panel over the stream', async ({ page, request }) => {
        test.skip(!process.env.SLACK_SIGNING_SECRET, 'SLACK_SIGNING_SECRET is not set - cannot sign a Slack event');

        // Recorded from before the page loads: the stream opens as soon as the first
        // message creates the conversation, so a wait registered after sendMessage()
        // could miss it and hang until the timeout.
        const chat = h.recordChatRequests(page);
        await page.goto(h.pagePath());
        const config = await h.bootConfig(page);
        await h.openChat(page);
        await h.sendMessage(page, 'e2e needs a reply ' + Date.now());

        await expect.poll(() => chat.some((r) => r.includes('/bluefairy_slc/stream/index')), {
            message: 'the reply stream was never opened for the new conversation',
        }).toBe(true);

        const thread = h.slackThreadFor(await h.storedToken(page, config.storeCode));
        test.skip(Boolean(thread.reason), thread.reason || '');

        const reply = 'e2e agent reply <b>not bold</b> ' + Date.now();
        const res = await h.postSignedAgentReply(request, { channel: thread.channel, threadTs: thread.threadTs, text: reply });
        expect(res.status(), 'the store refused the signed event: ' + (await res.text())).toBe(200);

        const row = page.locator(h.SEL.item).filter({ hasText: reply });
        await expect(row).toHaveCount(1, { timeout: 20000 });
        await expect(row).toContainText('E2E Agent');
        // Rendered as text: the tags are visible characters, not markup.
        await expect(row.locator('b')).toHaveCount(0);
    });

    test('a 3 MB PNG is uploaded and downloads back byte-for-byte', async ({ page }) => {
        await page.goto(h.pagePath());
        const config = await h.bootConfig(page);
        const png = h.generatePng(3 * 1024 * 1024);
        test.skip(!config.uploadsEnabled, 'file uploads are disabled for this store view');
        test.skip(!config.allowedMimeTypes.includes('image/png'), 'image/png is not an allowed upload type here');
        test.skip(config.maxUploadBytes < png.length, `upload limit ${config.maxUploadBytes} is below the ${png.length}-byte specimen`);

        await h.openChat(page);
        const uploaded = page.waitForResponse((r) => /\/V1\/bluefairy-slack-chat\/upload$/.test(r.url()));
        await page.locator(h.SEL.file).setInputFiles({ name: 'e2e-3mb.png', mimeType: 'image/png', buffer: png });
        const response = await uploaded;
        expect(response.status(), 'upload refused: ' + (await response.text())).toBe(200);
        const body = await response.json();
        expect(body.attachment_name).toBe('e2e-3mb.png');
        expect(body.attachment_mime).toBe('image/png');
        expect(body.attachment_url).toMatch(/bluefairy_slc\/file\/download/);

        const row = page.locator(h.SEL.item).filter({ hasText: 'e2e-3mb.png' });
        await expect(row).toHaveCount(1);
        await expect(row).not.toContainText('Not sent');

        // The download carries the token in a header, never the URL.
        const fetched = page.waitForRequest((r) => r.url() === body.attachment_url);
        const download = page.waitForEvent('download');
        await row.getByRole('button').click();
        const req = await fetched;
        expect(req.headers()['x-bfslc-token']).toMatch(/^[a-f0-9]{64}$/);
        expect(req.url()).not.toContain('token');
        const file = await (await download).path();
        expect(require('fs').readFileSync(file).equals(png)).toBe(true);
    });

    test('the conversation survives a reload, and the reload is lazy too', async ({ page }) => {
        await page.goto(h.pagePath());
        const config = await h.bootConfig(page);
        await h.openChat(page);
        const text = 'e2e persist ' + Date.now();
        await h.sendMessage(page, text);
        const token = await h.storedToken(page, config.storeCode);
        expect(token).toMatch(/^[a-f0-9]{64}$/);

        const chat = h.recordChatRequests(page);
        await page.reload();
        await page.waitForLoadState('networkidle');
        expect(chat, 'the reloaded page made a chat request before the bubble was opened').toEqual([]);

        const status = page.waitForResponse((r) => /\/V1\/bluefairy-slack-chat\/session\/status$/.test(r.url()));
        await h.openChat(page);
        expect((await status).status()).toBe(200);
        await expect(page.locator(h.SEL.item).filter({ hasText: text })).toHaveCount(1);
        expect(await h.storedToken(page, config.storeCode)).toBe(token);
        // The name/email fields are for a visitor without a conversation.
        await expect(page.locator(h.SEL.name)).toBeHidden();
    });

    /**
     * The operator sets the store to Away before the run and passes the configured text:
     *   bin/magento config:set bluefairy_slack_chat/availability/manual_status away
     *   bin/magento config:set bluefairy_slack_chat/availability/msg_away "We're away - leave a message."
     *   bin/magento cache:clean config
     *   BFSLC_EXPECT_AWAY_MESSAGE="We're away - leave a message." npx playwright test ...
     * and restores manual_status to "auto" afterwards.
     */
    test('shows the Away banner when the store is set to Away', async ({ page }) => {
        const expected = process.env.BFSLC_EXPECT_AWAY_MESSAGE;
        test.skip(!expected, 'BFSLC_EXPECT_AWAY_MESSAGE is not set - the operator has not put the store in Away (see this test\'s comment)');

        await page.goto(h.pagePath());
        const availability = page.waitForResponse((r) => /\/V1\/bluefairy-slack-chat\/availability\//.test(r.url()));
        await h.openChat(page);
        const body = await (await availability).json();
        expect(body.status).toBe('away');
        await expect(page.locator(h.SEL.banner)).toBeVisible();
        await expect(page.locator(h.SEL.banner)).toHaveText(expected);
    });

    /**
     * The policy must admit THIS script - by the nonce on its own tag or by its own
     * sha256 (etc/csp_whitelist.xml; Hyvä's registerInlineScript sends the same hash
     * on cached pages). Any other sha256 in script-src proves nothing about it.
     */
    test('the inline component script carries a CSP nonce and a strict policy admits it by name', async ({ request }) => {
        const response = await request.get(h.pagePath());
        expect(response.status()).toBe(200);
        const html = await response.text();

        const match = html.match(/<script nonce="([^"]*)">(\s*\(function \(\) \{\s*'use strict';[\s\S]*?Alpine\.data\('bfslcChat'[\s\S]*?)<\/script>/);
        expect(match, 'the widget\'s inline script is not on the page').not.toBeNull();
        expect(match[1], 'the inline script has an empty nonce').toMatch(/^[A-Za-z0-9+/=]{16,}$/);

        const pinned = h.whitelistedScriptHash();
        expect(h.sha256(match[2]), 'the served script body is not the one whose hash etc/csp_whitelist.xml pins').toBe(pinned);

        const scriptSrc = h.scriptSrc(response.headers());
        test.skip(scriptSrc === '', 'the response sends no script-src (Magento_Csp off or replaced) - nothing to check the script against');
        test.skip(scriptSrc.includes("'unsafe-inline'"), "script-src allows 'unsafe-inline', so Magento sends no nonces or hashes and the strict-mode admission cannot be shown on this store");

        const byNonce = scriptSrc.includes("'nonce-" + match[1] + "'");
        const byHash = scriptSrc.includes("'sha256-" + pinned + "'");
        expect(byNonce || byHash, "script-src names neither this script's nonce nor its pinned sha256 (" + pinned + '): ' + scriptSrc).toBe(true);
    });

    /**
     * The component is written for Alpine's CSP build. It is only exercised by that
     * build when the store serves it (alpine3-csp) or when the policy forbids eval,
     * which the standard build cannot run without. On any other store a working
     * widget proves nothing about CSP compatibility, so the spec says so and skips.
     */
    test('the component starts under the CSP Alpine build with no CSP violation', async ({ page }) => {
        const violations = [];
        page.on('console', (msg) => {
            if (/Content Security Policy|Refused to (execute|evaluate)/i.test(msg.text())) {
                violations.push(msg.text());
            }
        });
        const response = await page.goto(h.pagePath());
        const html = await response.text();
        const scriptSrc = h.scriptSrc(response.headers());
        const cspAlpine = /<script[^>]+src="[^"]*\/alpine3-csp(\.min)?\.js"/.test(html);
        const noEval = scriptSrc !== '' && !scriptSrc.includes("'unsafe-eval'");
        test.skip(!cspAlpine && !noEval, "this store serves the standard Alpine build and its script-src allows 'unsafe-eval' (or sends none) - the CSP build is not in use, so this run cannot show the component works under it");

        await h.openChat(page);
        // The panel opening is the component running: x-show="isOpen" only flips if
        // Alpine evaluated the component's directives.
        await expect(page.locator(h.SEL.panel)).toBeVisible();
        expect(violations).toEqual([]);
    });
});
