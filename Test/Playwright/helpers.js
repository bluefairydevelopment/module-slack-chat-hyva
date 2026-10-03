/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Shared helpers for the Hyvä Slack Live Chat specs. Nothing here needs test-only
 * code in the module: the widget is driven through its own markup, the agent reply
 * through the real Slack Events endpoint with a real signature, and the one fact
 * the browser cannot know (the conversation's Slack thread) is READ from the
 * database by the test, never written.
 */
const crypto = require('crypto');
const zlib = require('zlib');
const { execFileSync } = require('child_process');
const { expect } = require('@playwright/test');

const SEL = {
    bubble: 'button[aria-controls="bfslc-panel"]',
    panel: '#bfslc-panel',
    name: '#bfslc-name',
    message: '#bfslc-message',
    file: '#bfslc-file',
    log: '#bfslc-panel [role="log"]',
    item: '#bfslc-panel [data-bfslc-message]',
    banner: '#bfslc-panel [data-bfslc-banner]',
    config: '[data-bfslc-config]',
};

const CHAT_REQUEST = /\/rest\/[^/]+\/V1\/bluefairy-slack-chat\/|\/bluefairy_slc\/stream\//;

function pagePath() {
    return process.env.PAGE_PATH || '/';
}

/** Every chat request the page makes, from now on. */
function recordChatRequests(page) {
    const seen = [];
    page.on('request', (req) => {
        if (CHAT_REQUEST.test(req.url())) {
            seen.push(req.method() + ' ' + req.url());
        }
    });
    return seen;
}

/** The boot config the widget renders, parsed from its data attribute. */
async function bootConfig(page) {
    const raw = await page.locator(SEL.config).getAttribute('data-bfslc-config');
    expect(raw, 'the widget is not on this page (no data-bfslc-config)').toBeTruthy();
    return JSON.parse(raw);
}

async function openChat(page) {
    await page.locator(SEL.bubble).click();
    await expect(page.locator(SEL.panel)).toBeVisible();
}

/** Send a text message and wait for the server to accept it. */
async function sendMessage(page, text) {
    const accepted = page.waitForResponse(
        (res) => /\/V1\/bluefairy-slack-chat\/message$/.test(res.url()) && res.request().method() === 'POST'
    );
    await page.locator(SEL.message).fill(text);
    await page.locator(SEL.message).press('Enter');
    const response = await accepted;
    expect(response.status(), 'POST message was refused').toBe(200);
    const row = page.locator(SEL.item).filter({ hasText: text });
    await expect(row).toHaveCount(1);
    await expect(row).not.toContainText('Not sent');
    return response.json();
}

async function storedToken(page, storeCode) {
    return page.evaluate((key) => window.localStorage.getItem(key), 'bfslc_token_' + storeCode);
}

/**
 * The conversation's Slack channel and thread, read from bfslc_sessions. Returns a
 * reason string instead when the lookup is impossible, so the caller can skip with it.
 */
function slackThreadFor(token) {
    const need = ['DB_USER', 'DB_NAME'];
    const missing = need.filter((k) => !process.env[k]);
    if (missing.length) {
        return { reason: 'database lookup needs ' + missing.join(', ') + ' (see playwright.config.js)' };
    }
    if (!/^[a-f0-9]{64}$/.test(token || '')) {
        return { reason: 'no well-formed conversation token in localStorage' };
    }
    const table = (process.env.DB_TABLE_PREFIX || '') + 'bfslc_sessions';
    if (!/^[A-Za-z0-9_]+$/.test(table)) {
        return { reason: 'DB_TABLE_PREFIX is not a plain identifier' };
    }
    const args = [
        '-h', process.env.DB_HOST || '127.0.0.1',
        '-P', process.env.DB_PORT || '3306',
        '-u', process.env.DB_USER,
        '-N', '-B',
        process.env.DB_NAME,
        // The token was checked against /^[a-f0-9]{64}$/ above, so it cannot break out of the literal.
        '-e', `SELECT channel_id, IFNULL(slack_thread_ts, '') FROM ${table} WHERE session_token = '${token}' LIMIT 1`,
    ];
    const env = { ...process.env };
    if (process.env.DB_PASSWORD) {
        env.MYSQL_PWD = process.env.DB_PASSWORD;
    }
    let out;
    try {
        out = execFileSync('mysql', args, { env, encoding: 'utf8' }).trim();
    } catch (e) {
        return { reason: 'mysql lookup failed: ' + String(e.message).split('\n')[0] };
    }
    const [channel, threadTs] = out.split('\t');
    if (!channel || !threadTs) {
        return {
            reason: 'the conversation has no Slack thread (channel "' + (channel || '') + '", thread_ts "'
                + (threadTs || '') + '") - the rig posts by webhook or Slack refused the first message, '
                + 'so there is no thread for an agent to reply in',
        };
    }
    return { channel, threadTs };
}

/** POST a Slack Events API message callback, signed exactly as Slack signs it. */
async function postSignedAgentReply(request, { channel, threadTs, text }) {
    const secret = process.env.SLACK_SIGNING_SECRET;
    const now = Math.floor(Date.now() / 1000);
    const body = JSON.stringify({
        token: 'deprecated-verification-token',
        team_id: 'T0E2ETEST',
        api_app_id: 'A0E2ETEST',
        type: 'event_callback',
        event_id: 'Ev' + crypto.randomBytes(6).toString('hex').toUpperCase(),
        event_time: now,
        event: {
            type: 'message',
            channel,
            user: 'U0E2EAGENT',
            text,
            ts: now + '.' + String(Math.floor(Math.random() * 1e6)).padStart(6, '0'),
            thread_ts: threadTs,
            user_profile: { display_name: 'E2E Agent', real_name: 'E2E Agent' },
        },
    });
    const signature = 'v0=' + crypto.createHmac('sha256', secret).update('v0:' + now + ':' + body).digest('hex');
    return request.post('/bluefairy_slc/slack/events', {
        data: body,
        headers: {
            'Content-Type': 'application/json',
            'X-Slack-Request-Timestamp': String(now),
            'X-Slack-Signature': signature,
        },
    });
}

/**
 * A valid PNG of about `bytes` bytes. Random RGB pixels do not compress, so the
 * file size tracks the pixel count; a uniform image would deflate to a few KB and
 * never exercise the size path.
 */
function generatePng(bytes) {
    const width = 1024;
    const height = Math.ceil(bytes / (width * 3));
    const raw = Buffer.alloc((width * 3 + 1) * height);
    for (let y = 0; y < height; y++) {
        const row = y * (width * 3 + 1);
        raw[row] = 0;
        crypto.randomFillSync(raw, row + 1, width * 3);
    }
    const crcTable = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        return c >>> 0;
    });
    const crc32 = (buf) => {
        let c = 0xffffffff;
        for (const b of buf) {
            c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
        }
        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (type, data) => {
        const len = Buffer.alloc(4);
        len.writeUInt32BE(data.length);
        const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
        const crc = Buffer.alloc(4);
        crc.writeUInt32BE(crc32(td));
        return Buffer.concat([len, td, crc]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    ihdr[9] = 2;
    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw, { level: 1 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

module.exports = {
    SEL,
    CHAT_REQUEST,
    pagePath,
    recordChatRequests,
    bootConfig,
    openChat,
    sendMessage,
    storedToken,
    slackThreadFor,
    postSignedAgentReply,
    generatePng,
};
