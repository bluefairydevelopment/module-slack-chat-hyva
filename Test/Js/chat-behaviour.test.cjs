/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Behaviour of the Hyvä Alpine component (view/frontend/templates/chat-js.phtml)
 * against a fake window, document, fetch, localStorage, timers and EventSource.
 * Plain node, no npm install:
 *     node --test Test/Js/chat-behaviour.test.cjs
 *
 * The script body is cut out of the template exactly as the browser receives it
 * (between the nonce-carrying <script> tag and </script>), run with a stand-in
 * Alpine whose data() captures the component factory, and the component object
 * is then driven directly - Alpine's reactivity is not needed to test the logic,
 * and the CSP-build template rules are WidgetTemplateTest's job.
 *
 * The labels are the keys BlueFairy\SlackChat\Model\Widget\BootConfigProvider
 * ::getLabels() supplies (plus the Hyvä-only "unread"), worded as it words them.
 *
 * BFSLC_CHAT_JS points the suite at another copy of the template (used to show
 * each of these tests going red on a deliberately broken component).
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const test = require('node:test');

const TEMPLATE = process.env.BFSLC_CHAT_JS
    || path.join(__dirname, '..', '..', 'view', 'frontend', 'templates', 'chat-js.phtml');

/** The script body as served: everything between the opening tag and </script>. */
function scriptBody() {
    const source = fs.readFileSync(TEMPLATE, 'utf8');
    const open = source.indexOf('<script nonce=');
    assert.notEqual(open, -1, 'CONTROL: the template has no nonce-carrying script tag');
    const start = source.indexOf('">', open) + 2;
    const end = source.indexOf('</script>', start);
    return source.slice(start, end);
}

const REST = 'https://shop.example/rest/default/V1/bluefairy-slack-chat/';
const TOKEN = 'a'.repeat(64);
const TOKEN2 = 'b'.repeat(64);
const I18N = {
    open: 'Open chat', you: 'You', agent: 'Support', system: 'Notice', sending: 'Sending...',
    notSent: 'Not sent', notDelivered: 'Not delivered yet. We will keep trying for a while.',
    deliveryPending: 'Sending to our team…', retry: 'Retry', download: 'Download',
    ended: 'This conversation has ended.', rateLimited: 'Too fast', unavailable: 'Unavailable',
    tooLarge: 'Too large', typeNotAllowed: 'Type not allowed', uploadsOff: 'Uploads off',
    fileEmpty: 'The file is empty.', unread: '%1 unread', genericError: 'Oops',
};

/**
 * A component wired to fakes. `routes(path, body, method)` answers each REST call
 * with [status, json]; it may return a Promise to hold a response back.
 */
function setup(routes, options = {}) {
    const store = options.token ? { bfslc_token_default: options.token } : {};
    const calls = [];
    const sources = [];
    const timers = { intervals: new Map(), timeouts: new Map(), next: 1 };
    const fetched = [];
    const docListeners = {};
    const winListeners = {};

    function FakeEventSource(url) {
        this.url = url;
        this.listeners = {};
        this.closed = false;
        this.readyState = 1;
        this.onerror = null;
        this.addEventListener = (type, fn) => { this.listeners[type] = fn; };
        this.close = () => { this.closed = true; this.readyState = 2; };
        sources.push(this);
    }
    FakeEventSource.CLOSED = 2;

    const location = new URL(options.href || 'https://shop.example/p.html?utm_source=mail&coupon=SECRET#reviews');
    const window = {
        location,
        localStorage: {
            getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); },
            removeItem: (k) => { delete store[k]; },
        },
        addEventListener: (type, fn) => { winListeners[type] = fn; },
        setInterval: (fn, ms) => { const id = timers.next++; timers.intervals.set(id, { fn, ms }); return id; },
        clearInterval: (id) => { timers.intervals.delete(id); },
        setTimeout: (fn, ms) => { const id = timers.next++; timers.timeouts.set(id, { fn, ms }); return id; },
        clearTimeout: (id) => { timers.timeouts.delete(id); },
        EventSource: options.noEventSource ? undefined : FakeEventSource,
        fetch: async (url, init) => {
            if (!url.startsWith(REST)) {
                fetched.push({ url, init });
                return { ok: true, status: 200, blob: async () => ({ size: 1 }) };
            }
            const p = url.slice(REST.length);
            const body = init && init.body ? JSON.parse(init.body) : null;
            calls.push({ path: p, body, method: init.method, headers: init.headers });
            const [status, json] = await routes(p, body, init.method);
            return { ok: status >= 200 && status < 300, status, text: async () => (json === null ? '' : JSON.stringify(json)) };
        },
    };
    const document = {
        hidden: false,
        addEventListener: (type, fn) => { docListeners[type] = fn; },
        createElement: () => ({ click() {}, remove() {} }),
        body: { appendChild() {} },
    };
    class FakeURL extends URL {
        static createObjectURL() { return 'blob:https://shop.example/1'; }
        static revokeObjectURL() {}
    }

    let factory = null;
    window.Alpine = { data: (name, fn) => { assert.equal(name, 'bfslcChat'); factory = fn; } };
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', 'URL', 'FileReader', scriptBody())(window, document, FakeURL, options.FileReader);
    assert.equal(typeof factory, 'function', 'CONTROL: the script registered no component');

    const c = factory();
    c.$el = { dataset: { bfslcConfig: JSON.stringify(Object.assign({
        restRoot: REST.replace(/\/$/, options.restRootSlash === false ? '' : '/'),
        streamUrl: 'https://shop.example/bluefairy_slc/stream/index/',
        storeId: 1, storeCode: 'default', productId: 4, categoryId: null, cmsPageId: null,
        uploadsEnabled: true, maxUploadBytes: 1000, allowedMimeTypes: ['image/png'], i18n: I18N,
    }, options.config || {})) } };
    c.$refs = {};
    c.$nextTick = (fn) => fn();
    c.init();

    return {
        c, store, calls, sources, fetched, timers, document, window,
        paths: () => calls.map((x) => x.path),
        intervalMs: () => [...timers.intervals.values()].map((x) => x.ms),
        timeoutMs: () => [...timers.timeouts.values()].map((x) => x.ms),
        fireTimeouts: async (ms) => {
            for (const [id, t] of [...timers.timeouts.entries()]) {
                if (t.ms === ms) { timers.timeouts.delete(id); t.fn(); }
            }
            await settle();
        },
        tick: async () => { for (const t of [...timers.intervals.values()]) t.fn(); await settle(); },
        setHidden: async (hidden) => { document.hidden = hidden; docListeners.visibilitychange(); await settle(); },
    };
}

async function settle() {
    for (let i = 0; i < 40; i++) {
        await new Promise((r) => setImmediate(r));
    }
}

const active = (token) => [200, { session_token: token, status: 'active', availability: 'online', availability_message: 'Hi' }];
const out = (id, body, delivery = 'delivered') => ({ id, direction: 'outbound', body, author_name: 'V', delivery_status: delivery, attachment_url: null, attachment_name: null });
const inbound = (id, body) => ({ id, direction: 'inbound', body, author_name: 'Agent', delivery_status: 'delivered', attachment_url: null, attachment_name: null });
const items = (...list) => [200, { items: list }];

async function sendText(h, body) {
    h.c.draft = body;
    h.c.send();
    await settle();
}

/* ---------------------------------------------------------------- laziness */

test('nothing is requested until the bubble is opened, even when a token is stored', async () => {
    const h = setup(() => active(TOKEN), { token: TOKEN });
    await h.setHidden(true);
    await h.setHidden(false);
    assert.deepEqual(h.calls, []);
    assert.equal(h.sources.length, 0);
    assert.deepEqual(h.intervalMs(), []);
    h.c.open();
    await settle();
    assert.deepEqual(h.paths().slice(0, 2), ['session/status', 'messages'], 'CONTROL: opening does request');
});

/* ----------------------------------------------- finding 3: failed resume */

test('a transient failure resuming the stored conversation lets the next open try again', async () => {
    let statusAnswer = [503, { message: 'down' }];
    const h = setup((p) => (p === 'session/status' ? statusAnswer : items()), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.c.booted, false, 'booted stays true after a failed resume, so it is never retried');
    assert.equal(h.c.notice, 'Unavailable');
    assert.equal(h.store.bfslc_token_default, TOKEN, 'a transient failure must not forget the conversation');
    assert.equal(h.sources.length, 0);

    h.c.close();
    statusAnswer = active(TOKEN);
    h.c.open();
    await settle();
    assert.deepEqual(h.paths(), ['session/status', 'session/status', 'messages']);
    assert.equal(h.sources.length, 1, 'the reopen did not connect the stream');
    assert.equal(h.c.booted, true);
});

test('after a failed resume, a message that goes through loads the history and connects', async () => {
    const h = setup((p) => (p === 'session/status' ? [0, null]
        : p === 'message' ? [200, out(12, 'hi')]
            : p === 'messages' ? items(inbound(5, 'earlier reply')) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.sources.length, 0, 'CONTROL: the resume failed');
    await sendText(h, 'hi');
    assert.deepEqual(h.paths(), ['session/status', 'message', 'messages']);
    assert.equal(h.calls[1].body.sessionToken, TOKEN);
    assert.equal(h.sources.length, 1, 'a successful send after a failed resume never connected');
    assert.equal(h.c.hasSession, true, 'End chat stays hidden: the session was never marked active');
    assert.deepEqual(h.c.messages.map((m) => m.body), ['hi', 'earlier reply']);
});

/* --------------------------------------------------- finding 4: End chat */

test('End chat that the server refuses keeps the conversation and says so', async () => {
    let closeAnswer = [503, { message: 'down' }];
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'session/close' ? closeAnswer : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.c.endChat();
    await settle();
    assert.equal(h.c.token, TOKEN, 'a failed close dropped the conversation');
    assert.equal(h.store.bfslc_token_default, TOKEN);
    assert.equal(h.c.notice, 'Unavailable');
    assert.equal(h.c.messages.filter((m) => m.body === 'This conversation has ended.').length, 0,
        'a failed close was reported as ended');
    assert.equal(h.sources[0].closed, false);

    closeAnswer = [200, true];
    h.c.endChat();
    await settle();
    assert.equal(h.c.token, '', 'CONTROL: a successful close ends it');
    assert.equal(h.c.messages.filter((m) => m.body === 'This conversation has ended.').length, 1);
});

test('End chat on a conversation the server already closed (410) reports it ended', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'session/close' ? [410, { message: 'ended' }] : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.c.endChat();
    await settle();
    assert.equal(h.c.token, '');
    assert.equal(h.c.notice, '');
});

/* -------------------------- finding 5 + A7: polling, closed panel, hidden tab */

test('the stream is open only while the panel is open and the tab visible; closed = 30 s poll', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? items(inbound(7, 'hello')) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.sources.length, 1);
    assert.equal(h.sources[0].url, 'https://shop.example/bluefairy_slc/stream/index/?token=' + TOKEN + '&after=7');
    assert.deepEqual(h.intervalMs(), []);

    h.c.close();
    await settle();
    assert.equal(h.sources[0].closed, true, 'closing the panel left the stream open');
    assert.deepEqual(h.intervalMs(), [30000], 'a closed panel does not poll slowly for the badge');

    await h.setHidden(true);
    assert.deepEqual(h.intervalMs(), [], 'a hidden tab still polls');
    assert.equal(h.sources.length, 1);

    await h.setHidden(false);
    assert.deepEqual(h.intervalMs(), [30000]);

    h.c.open();
    await settle();
    assert.deepEqual(h.intervalMs(), [], 'the slow poll outlived the reopen');
    assert.equal(h.sources.length, 2);
    assert.equal(h.sources[1].closed, false);
    assert.match(h.sources[1].url, /&after=7$/, 'the reconnect does not catch up from the newest id held');
});

test('a hidden tab closes the stream and showing it again reconnects', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    await h.setHidden(true);
    assert.equal(h.sources[0].closed, true);
    await h.setHidden(false);
    assert.equal(h.sources.length, 2);
    assert.equal(h.sources[1].closed, false);
});

test('while closed, polling raises the unread count and the bubble label says so', async () => {
    let next = items();
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? next : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    h.c.close();
    await settle();
    assert.equal(h.c.bubbleLabel, 'Open chat');
    next = items(inbound(9, 'a reply'), inbound(10, 'another'));
    await h.tick();
    assert.equal(h.c.unreadCount, 2);
    assert.equal(h.c.unreadAnnouncement, '2 unread');
    assert.equal(h.c.bubbleLabel, 'Open chat (2 unread)');
});

test('every 10th poll checks the status, and a closed conversation is noticed', async () => {
    let status = active(TOKEN);
    const h = setup((p) => (p === 'session/status' ? status : items()), { token: TOKEN, noEventSource: true });
    h.c.open();
    await settle();
    assert.deepEqual(h.intervalMs(), [3000], 'CONTROL: without EventSource the open panel polls every 3 s');
    const statusCalls = () => h.paths().filter((p) => p === 'session/status').length;
    const pollsSoFar = h.paths().filter((p) => p === 'messages').length - 1;
    for (let i = pollsSoFar; i < 9; i++) {
        await h.tick();
    }
    assert.equal(statusCalls(), 1, 'the status was checked before the 10th poll');
    status = [200, { session_token: TOKEN, status: 'closed', availability: 'online', availability_message: '' }];
    await h.tick();
    assert.equal(h.paths().filter((p) => p === 'messages').length, 11);
    assert.equal(statusCalls(), 2, 'the 10th poll did not check the status');
    assert.equal(h.c.token, '', 'a closed conversation was not noticed by polling');
    assert.deepEqual(h.intervalMs(), []);
});

/* ------------------------------------------- finding 11: token changes */

test('a poll answer for a conversation that has since ended is dropped', async () => {
    let release;
    let hold = false;
    const h = setup((p) => {
        if (p === 'session/status') return active(TOKEN);
        if (p === 'messages' && hold) return new Promise((r) => { release = () => r(items(inbound(50, 'stale'))); });
        if (p === 'session/close') return [200, true];
        return items();
    }, { token: TOKEN });
    h.c.open();
    await settle();
    h.c.close();
    await settle();
    hold = true;
    await h.tick();
    assert.equal(typeof release, 'function', 'CONTROL: the poll is being held');
    h.c.endChat();
    await settle();
    release();
    await settle();
    assert.equal(h.c.messages.filter((m) => m.body === 'stale').length, 0, 'a late answer for the old conversation was shown');
    assert.equal(h.c.messages.filter((m) => m.body === 'This conversation has ended.').length, 1, 'CONTROL');
});

test('a late "gone" for the old conversation does not drop its replacement', async () => {
    let release;
    let sessions = 0;
    const h = setup((p, body) => {
        if (p === 'session') return active(++sessions === 1 ? TOKEN : 'c'.repeat(64));
        if (p === 'message' && body.body === 'slow') return new Promise((r) => { release = () => r([410, { message: 'ended' }]); });
        if (p === 'message') return [200, out(30 + sessions, body.body)];
        return items();
    });
    h.c.open();
    await settle();
    await sendText(h, 'slow');
    h.c.conversationEnded();
    h.c.token = TOKEN2;
    h.c.sessionStatus = 'active';
    release();
    await settle();
    assert.equal(h.c.token, TOKEN2, 'a 410 for the old token dropped the new conversation');
    const sent = h.calls.filter((x) => x.path === 'message');
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.sessionToken, TOKEN2, 'the resend did not go to the current conversation');
    assert.equal(h.paths().filter((p) => p === 'session').length, 1, 'a needless third conversation was started');
});

/* ---------------------------------------------------- finding 13 bundle */

test('400 bfslc_invalid_token on resume is treated as gone; another 400 is not', async () => {
    const h = setup((p) => (p === 'session/status'
        ? [400, { message: 'Invalid session token.', parameters: { error_code: 'bfslc_invalid_token' } }]
        : p === 'availability/0' ? [200, { status: 'online', message: 'Hi' }] : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal('bfslc_token_default' in h.store, false);
    assert.deepEqual(h.paths(), ['session/status', 'availability/0']);

    const k = setup((p) => (p === 'session/status' ? [400, { message: 'x', parameters: { error_code: 'bfslc_other' } }] : [500, null]), { token: TOKEN });
    k.c.open();
    await settle();
    assert.equal(k.store.bfslc_token_default, TOKEN, 'CONTROL: an unrelated 400 does not forget the conversation');
});

test('400 bfslc_invalid_token on a send starts a new conversation and resends once', async () => {
    let sessions = 0;
    const h = setup((p, body) => {
        if (p === 'session/status') return active(TOKEN);
        if (p === 'session') return active(++sessions === 1 ? TOKEN2 : 'c'.repeat(64));
        if (p === 'message' && body.sessionToken === TOKEN) return [400, { message: 'Invalid', parameters: { error_code: 'bfslc_invalid_token' } }];
        if (p === 'message') return [200, out(40, body.body)];
        return items();
    }, { token: TOKEN });
    h.c.open();
    await settle();
    await sendText(h, 'hello');
    const sent = h.calls.filter((x) => x.path === 'message').map((x) => x.body.sessionToken);
    assert.deepEqual(sent, [TOKEN, TOKEN2]);
    assert.equal(h.c.messages[0].state, 'sent');
});

test('an attachment URL on another origin is neither offered nor fetched with the token', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(
            Object.assign(inbound(1, ''), { attachment_url: 'https://evil.example/steal', attachment_name: 'evil.png' }),
            Object.assign(inbound(2, ''), { attachment_url: 'https://shop.example/bluefairy_slc/file/download/id/2/', attachment_name: 'ok.png' }),
            Object.assign(inbound(3, ''), { attachment_url: 'javascript:alert(1)', attachment_name: 'js.png' })
        ) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    const [evil, ok, js] = h.c.messages;
    assert.equal(evil.canDownload, false, 'a foreign-origin attachment is offered as a download');
    assert.equal(evil.isPendingFile, true);
    assert.equal(js.canDownload, false);
    assert.equal(ok.canDownload, true, 'CONTROL: a same-origin attachment is offered');

    h.c.message = evil;
    h.c.openAttachment();
    h.c.message = ok;
    h.c.openAttachment();
    await settle();
    assert.deepEqual(h.fetched.map((f) => f.url), ['https://shop.example/bluefairy_slc/file/download/id/2/']);
    assert.equal(h.fetched[0].init.headers['X-Bfslc-Token'], TOKEN);
});

test('an empty file is refused in the browser and never uploaded', async () => {
    const h = setup(() => [500, null]);
    const input = { files: [{ name: 'empty.png', type: 'image/png', size: 0 }], value: 'C:\\fakepath\\empty.png' };
    h.c.onFileChosen({ target: input });
    await settle();
    assert.equal(h.c.notice, 'The file is empty.');
    assert.deepEqual(h.calls, []);
    assert.equal(h.c.messages.length, 0);

    const big = { files: [{ name: 'big.png', type: 'image/png', size: 5000 }], value: 'x' };
    h.c.onFileChosen({ target: big });
    assert.equal(h.c.notice, 'Too large', 'CONTROL: the size checks run');
});

test('the page URL sent with a new conversation has no query string and no fragment', async () => {
    const h = setup((p, body) => (p === 'session' ? active(TOKEN) : p === 'message' ? [200, out(1, body.body)] : items()));
    h.c.open();
    await settle();
    await sendText(h, 'hi');
    const start = h.calls.find((x) => x.path === 'session');
    assert.equal(start.body.originUrl, 'https://shop.example/p.html');
    assert.equal(start.body.productId, 4, 'CONTROL: the start payload is the one checked');
});

test('a restRoot without a trailing slash still reaches the right routes, with X-Requested-With', async () => {
    const h = setup(() => [200, { status: 'online', message: 'Hi' }], { restRootSlash: false });
    h.c.open();
    await settle();
    assert.deepEqual(h.paths(), ['availability/0']);
    assert.equal(h.calls[0].headers['X-Requested-With'], 'XMLHttpRequest');
});

/* ------------------------------------------------------- delivery wording */

test('delivery states read as the server now behaves; only an unsent message offers Retry', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(out(1, 'queued', 'pending'), out(2, 'stuck', 'failed'), out(3, 'done', 'delivered')) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    const [pending, failed, done] = h.c.messages;
    assert.equal(pending.statusLabel, 'Sending to our team…');
    assert.equal(failed.statusLabel, 'Not delivered yet. We will keep trying for a while.');
    assert.equal(failed.isFailed, false, 'a server-side retry is pending; a visitor Retry would post a duplicate');
    assert.equal(done.statusLabel, '');
    assert.equal(done.hasStatus, false);

    await sendText(h, 'refused');
    const refused = h.c.messages[3];
    assert.equal(refused.statusLabel, 'Not sent: Oops');
    assert.equal(refused.isFailed, true, 'a message the store never accepted lost its Retry');
});

/* ----------------------------- delivery statuses settle (Luma parity) */

test('a pending message is re-fetched every 30 s from its id until it is delivered', async () => {
    let state = 'pending';
    const h = setup((p, body) => {
        if (p === 'session/status') return active(TOKEN);
        if (p === 'messages' && body.afterId === 0) return items(inbound(3, 'hi'), out(4, 'mine', 'pending'));
        if (p === 'messages') return items(out(4, 'mine', state));
        return [500, null];
    }, { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.c.messages[1].statusLabel, 'Sending to our team…');
    assert.deepEqual(h.timeoutMs(), [30000], 'an unsettled message is not re-fetched');

    state = 'failed';
    await h.fireTimeouts(30000);
    const refetch = h.calls.filter((x) => x.path === 'messages').pop();
    assert.equal(refetch.body.afterId, 3, 'the re-fetch does not start just below the lowest unsettled id');
    assert.equal(h.c.messages[1].statusLabel, 'Not delivered yet. We will keep trying for a while.');
    assert.deepEqual(h.timeoutMs(), [30000], 'a failed message stopped being re-fetched while the server still retries');

    state = 'delivered';
    await h.fireTimeouts(30000);
    assert.equal(h.c.messages[1].statusLabel, '');
    assert.deepEqual(h.timeoutMs(), [], 'a delivered message is still being re-fetched');
});

test('settling stops while the panel is closed or the tab hidden, and resumes when seen again', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? items(out(4, 'mine', 'failed')) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.deepEqual(h.timeoutMs(), [30000], 'CONTROL: settling is armed');
    h.c.close();
    await settle();
    assert.deepEqual(h.timeoutMs(), [], 'a closed panel keeps re-fetching statuses');
    h.c.open();
    await settle();
    assert.deepEqual(h.timeoutMs(), [30000]);
    await h.setHidden(true);
    assert.deepEqual(h.timeoutMs(), [], 'a hidden tab keeps re-fetching statuses');
    await h.setHidden(false);
    assert.deepEqual(h.timeoutMs(), [30000]);
});

test('a message sent more than 50 minutes ago is no longer waited on', async () => {
    const old = new Date(Date.now() - 51 * 60000).toISOString();
    const recent = new Date(Date.now() - 49 * 60000).toISOString();
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(4, 'old', 'failed'), { created_at: old })) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.c.messages[0].statusLabel, 'Not delivered yet. We will keep trying for a while.');
    assert.deepEqual(h.timeoutMs(), [], 'a 51-minute-old message is still re-fetched');

    const k = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(4, 'recent', 'failed'), { created_at: recent })) : [500, null]), { token: TOKEN });
    k.c.open();
    await settle();
    assert.deepEqual(k.timeoutMs(), [30000], 'CONTROL: a 49-minute-old one is');
});
