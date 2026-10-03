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
    notSent: 'Not sent',
    deliveryPending: 'Sending to our team…', retry: 'Retry', download: 'Download',
    ended: 'This conversation has ended.', rateLimited: 'Too fast', unavailable: 'Unavailable',
    tooLarge: 'Too large', typeNotAllowed: 'Type not allowed', uploadsOff: 'Uploads off',
    fileEmpty: 'The file is empty.', unread: '%1 unread', genericError: 'Oops', networkError: 'Offline?',
    newConversation: 'Your previous conversation had ended, so this message started a new one.',
    notDeliveredRetrying: 'Not delivered yet. We will keep trying for about 48 minutes.', notDelivered: 'Not delivered.',
    uploadLimit: 'Upload limit reached', uploadQuota: 'Upload quota reached', storageFull: 'Storage full',
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
            const [status, json, headers = {}] = await routes(p, body, init.method);
            return {
                ok: status >= 200 && status < 300, status,
                headers: { get: (k) => (k in headers ? headers[k] : null) },
                text: async () => (json === null || json === undefined ? '' : JSON.stringify(json)),
            };
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
    const focused = [];
    c.$refs = { input: { focus() { focused.push('input'); } } };
    c.$nextTick = (fn) => fn();
    c.init();

    return {
        c, store, calls, sources, fetched, timers, document, window, focused,
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

test('delivery states read as the server now behaves; Retry only where a resend cannot duplicate', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(out(1, 'queued', 'pending'), Object.assign(out(2, 'stuck', 'failed'), { retrying: true }),
            out(3, 'done', 'delivered'), Object.assign(out(5, 'lost', 'failed'), { retrying: false })) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    const [pending, retrying, done, lost] = h.c.messages;
    assert.equal(pending.statusLabel, 'Sending to our team…');
    assert.equal(pending.canRetry, false);
    assert.equal(retrying.statusLabel, 'Not delivered yet. We will keep trying for about 48 minutes.');
    assert.equal(retrying.canRetry, false, 'the server is still retrying; a visitor Retry would post a duplicate');
    assert.equal(done.statusLabel, '');
    assert.equal(done.hasStatus, false);
    assert.equal(lost.statusLabel, 'Not delivered.');
    assert.equal(lost.canRetry, true, 'a message the server gave up on offers no Retry');
    assert.equal(lost.isFailed, true);

    await sendText(h, 'refused');
    const refused = h.c.messages[4];
    assert.equal(refused.statusLabel, 'Not sent: Oops');
    assert.equal(refused.canRetry, true, 'a message the store never accepted lost its Retry');
});

test('Retry on a message the server gave up on sends it again once, as a new message', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(5, 'lost', 'failed'), { retrying: false }))
            : p === 'message' ? [200, out(6, body.body, 'pending')] : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    h.c.message = h.c.messages[0];
    h.c.retry();
    h.c.retry();
    await settle();
    const sent = h.calls.filter((x) => x.path === 'message');
    assert.equal(sent.length, 1, 'a double click resent twice');
    assert.equal(sent[0].body.body, 'lost');
    assert.equal(h.c.messages[0].canRetry, false);
    assert.equal(h.c.messages[1].id, 6);
});

test('a status change re-sent over the stream redraws the message; delivered is final', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? items(out(4, 'mine', 'pending')) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    const frame = (raw) => h.sources[0].listeners.message({ data: JSON.stringify(raw) });
    frame(Object.assign(out(4, 'mine', 'failed'), { retrying: true }));
    assert.equal(h.c.messages.length, 1, 'a re-sent frame added a second copy');
    assert.equal(h.c.messages[0].statusLabel, 'Not delivered yet. We will keep trying for about 48 minutes.');
    frame(out(4, 'mine', 'delivered'));
    assert.equal(h.c.messages[0].statusLabel, '');
    frame(out(4, 'mine', 'pending'));
    assert.equal(h.c.messages[0].deliveryStatus, 'delivered', 'a late frame un-delivered a delivered message');
    assert.equal(h.c.messages[0].hasStatus, false);
});

/* -------------------------------------------------- stream endings */

test('event: idle closes the stream; the open panel polls every 60 s until a send, a reopen or the tab is shown', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN) : p === 'message' ? [200, out(9, body.body)] : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.idle({});
    await settle();
    assert.equal(h.sources[0].closed, true, 'an idle stream was left open');
    assert.deepEqual(h.intervalMs(), [60000], 'D5: an idle open panel does not poll every 60 s');
    assert.deepEqual(h.timeoutMs().filter((ms) => ms === 60000), [], 'an idle stream is scheduled to reconnect');
    assert.equal(h.sources.length, 1);

    await sendText(h, 'back again');
    assert.equal(h.sources.length, 2, 'a send did not reconnect the idle stream');
    assert.deepEqual(h.intervalMs(), [], 'the idle poll outlived the reconnect');

    h.sources[1].listeners.idle({});
    await settle();
    h.c.close();
    await settle();
    assert.deepEqual(h.intervalMs(), [30000], 'D5: the closed-panel 30 s poll stopped for an idle conversation');
    h.c.open();
    await settle();
    assert.equal(h.sources.length, 3, 'a reopen did not reconnect');
    assert.deepEqual(h.intervalMs(), []);

    h.sources[2].listeners.idle({});
    await settle();
    await h.setHidden(true);
    assert.deepEqual(h.intervalMs(), [], 'a hidden tab polls');
    await h.setHidden(false);
    assert.equal(h.sources.length, 4, 'showing the tab did not reconnect');
});

test('event: superseded closes the stream and polls every 30 s while open, never reconnecting', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.superseded({});
    await settle();
    assert.equal(h.sources[0].closed, true);
    assert.deepEqual(h.intervalMs(), [30000], 'a superseded open panel does not poll every 30 s');
    h.c.close();
    await settle();
    h.c.open();
    await settle();
    await h.setHidden(true);
    await h.setHidden(false);
    assert.equal(h.sources.length, 1, 'a superseded tab took the stream back');
    assert.deepEqual(h.intervalMs(), [30000]);
});

test('an ending from a stream that is no longer current is ignored', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    const old = h.sources[0];
    await h.setHidden(true);
    await h.setHidden(false);
    old.listeners.superseded({});
    await settle();
    assert.equal(h.sources[1].closed, false, 'a stale stream ending closed the current one');
});

/* ----------------------------------------------- upload refusals */

test('upload refusals (D6): own label by code, server words for a module 4xx, status wording otherwise, a 5xx generic', async () => {
    const cases = [
        [413, 'bfslc_upload_limit', 'Not sent: Upload limit reached'],
        [429, 'bfslc_upload_quota', 'Not sent: Upload quota reached'],
        [503, 'bfslc_storage_full', 'Not sent: Storage full'],
        [500, 'bfslc_unavailable', 'Not sent: Oops'],
        [413, 'bfslc_file_too_large', 'Not sent: x'],
        [413, null, 'Not sent: Too large'],
        [429, 'bfslc_rate_limited', 'Not sent: x'],
        [429, null, 'Not sent: Too fast'],
        [400, 'bfslc_bad_mime', 'Not sent: x'],
        [400, null, 'Not sent: Oops'],
        [403, null, 'Not sent: Uploads off'],
        [503, null, 'Not sent: Unavailable'],
    ];
    for (const [status, code, label] of cases) {
        const body = code === null ? { message: 'x' } : { message: 'x', parameters: { error_code: code } };
        const h = setup((p) => (p === 'session' ? active(TOKEN) : p === 'upload' ? [status, body] : items()), {
            FileReader: class { readAsDataURL() { this.result = 'data:image/png;base64,AAAA'; this.onload(); } },
        });
        h.c.onFileChosen({ target: { files: [{ name: 'a.png', type: 'image/png', size: 10 }], value: 'x' } });
        await settle();
        assert.equal(h.c.messages[0].statusLabel, label, status + ' ' + code);
    }
});
/* ----------------------------- delivery statuses settle (Luma parity) */

test('a pending message is re-fetched every 30 s from its id until it is delivered', async () => {
    let state = 'pending';
    const h = setup((p, body) => {
        if (p === 'session/status') return active(TOKEN);
        if (p === 'messages' && body.afterId === 0) return items(inbound(3, 'hi'), out(4, 'mine', 'pending'));
        if (p === 'messages') return items(Object.assign(out(4, 'mine', state), { retrying: state === 'failed' }));
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
    assert.equal(h.c.messages[1].statusLabel, 'Not delivered yet. We will keep trying for about 48 minutes.');
    assert.deepEqual(h.timeoutMs(), [30000], 'a failed message stopped being re-fetched while the server still retries');

    state = 'delivered';
    await h.fireTimeouts(30000);
    assert.equal(h.c.messages[1].statusLabel, '');
    assert.deepEqual(h.timeoutMs(), [], 'a delivered message is still being re-fetched');
});

test('settling stops while the panel is closed or the tab hidden, and resumes when seen again', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? items(Object.assign(out(4, 'mine', 'failed'), { retrying: true })) : [500, null]), { token: TOKEN });
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

test('a message sent more than 60 minutes ago is no longer waited on', async () => {
    const old = new Date(Date.now() - 61 * 60000).toISOString();
    const recent = new Date(Date.now() - 59 * 60000).toISOString();
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(4, 'old', 'failed'), { created_at: old, retrying: true })) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.c.messages[0].statusLabel, 'Not delivered yet. We will keep trying for about 48 minutes.');
    assert.deepEqual(h.timeoutMs(), [], 'a 61-minute-old message is still re-fetched');

    const k = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(4, 'recent', 'failed'), { created_at: recent, retrying: true })) : [500, null]), { token: TOKEN });
    k.c.open();
    await settle();
    assert.deepEqual(k.timeoutMs(), [30000], 'CONTROL: a 59-minute-old one is');
});

test('a message the server has given up on is final: no more re-fetching', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(Object.assign(out(4, 'lost', 'failed'), { retrying: false })) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.c.messages[0].statusLabel, 'Not delivered.', 'CONTROL: the message is the given-up one');
    assert.deepEqual(h.timeoutMs(), [], 'a message the server gave up on is still re-fetched');
});

test('a failed message without a retrying field is taken as still retrying: no Retry, still re-fetched', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? items(out(4, 'unknown', 'failed'), Object.assign(out(5, 'lost', 'failed'), { retrying: false })) : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    const [unknown, lost] = h.c.messages;
    assert.equal('retrying' in out(4, 'x', 'failed'), false, 'CONTROL: the specimen really lacks the field');
    assert.equal(unknown.statusLabel, 'Not delivered yet. We will keep trying for about 48 minutes.');
    assert.equal(unknown.canRetry, false, 'a message of unknown retry state offers a duplicating resend');
    assert.deepEqual(h.timeoutMs(), [30000], 'a message of unknown retry state is not re-fetched');
    assert.equal(lost.canRetry, true, 'CONTROL: an explicit retrying=false does offer Retry');
});

/* ------------------------------ cure round 2: parity with Luma (D5, D6) */

test('W2: an ended conversation forgets only its own token, not one another tab stored since', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.store.bfslc_token_default = TOKEN2;
    h.sources[0].listeners.closed({});
    await settle();
    assert.equal(h.c.token, '', 'CONTROL: this tab\'s conversation ended');
    assert.equal(h.store.bfslc_token_default, TOKEN2, 'another tab\'s newer conversation was erased');

    const k = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    k.c.open();
    await settle();
    k.sources[0].listeners.closed({});
    await settle();
    assert.equal('bfslc_token_default' in k.store, false, 'CONTROL: its own token is forgotten');
});

test('W5: a reconnect after idle that an older server refuses with 204 stays on the 60 s idle poll', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.idle({});
    await settle();
    h.c.close();
    await settle();
    h.c.open();
    await settle();
    assert.equal(h.sources.length, 2, 'CONTROL: reopening tries the stream');
    h.sources[1].readyState = 2;
    h.sources[1].onerror();
    await settle();
    assert.deepEqual(h.intervalMs(), [60000], 'a 204 after idle fell back to 3 s polling');
    assert.deepEqual(h.timeoutMs().filter((ms) => ms === 60000), [], 'a 204 after idle schedules a stream retry');

    await h.setHidden(true);
    await h.setHidden(false);
    assert.equal(h.sources.length, 3);
    h.sources[2].listeners.open({});
    h.sources[2].readyState = 2;
    h.sources[2].onerror();
    await settle();
    assert.deepEqual(h.intervalMs(), [3000], 'CONTROL: a stream that opened and then failed is the 3 s fallback');
    assert.deepEqual(h.timeoutMs().filter((ms) => ms === 60000), [60000]);
});

test('D5: a reconnect answered with 200 + event: idle stays on the 60 s poll', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.idle({});
    await settle();
    await h.setHidden(true);
    await h.setHidden(false);
    assert.equal(h.sources.length, 2);
    h.sources[1].listeners.open({});
    h.sources[1].listeners.idle({});
    await settle();
    assert.equal(h.sources[1].closed, true);
    assert.deepEqual(h.intervalMs(), [60000]);
});

test('D5: an idle poll that finds a new message leaves idle and reopens the stream after it', async () => {
    let next = items();
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'messages' ? next : [500, null]), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.idle({});
    await settle();
    await h.tick();
    assert.equal(h.sources.length, 1, 'a quiet poll reopened the stream');
    next = items(inbound(12, 'agent is back'));
    await h.tick();
    assert.equal(h.c.messages[0].body, 'agent is back');
    assert.equal(h.sources.length, 2, 'the agent answered, but the stream stayed shut');
    assert.match(h.sources[1].url, /&after=12$/);
    assert.deepEqual(h.intervalMs(), []);
});

test('W6: a superseded tab takes the stream back when the visitor sends from it', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN) : p === 'message' ? [200, out(8, body.body)] : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.sources[0].listeners.superseded({});
    await settle();
    assert.deepEqual(h.intervalMs(), [30000], 'CONTROL: superseded polls slowly');
    await sendText(h, 'here');
    assert.equal(h.sources.length, 2, 'the tab the visitor is typing in never retook the stream');
    assert.equal(h.sources[1].closed, false);
    assert.deepEqual(h.intervalMs(), []);
});

test('reconnecting: a stream that ran is reconnected at once; one that ended at once falls back', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items(inbound(4, 'x'))), { token: TOKEN });
    h.c.open();
    await settle();
    const realNow = Date.now;
    let now = realNow();
    Date.now = () => now;
    try {
        h.sources[0].listeners.open({});
        now += 300000;
        h.sources[0].listeners.reconnecting({});
        await settle();
        assert.equal(h.sources[0].closed, true);
        assert.equal(h.sources.length, 2, 'an expired stream was not reconnected');
        assert.match(h.sources[1].url, /&after=4$/);
        assert.deepEqual(h.intervalMs(), []);
        h.sources[1].listeners.open({});
        now += 2000;
        h.sources[1].listeners.reconnecting({});
        await settle();
        assert.equal(h.sources.length, 2, 'a stream that expires at once is hammered');
        assert.deepEqual(h.intervalMs(), [3000]);
        assert.deepEqual(h.timeoutMs().filter((ms) => ms === 60000), [60000]);
    } finally {
        Date.now = realNow;
    }
});

test('the stream listens for the events the server sends (StreamRunner::EVENT_*) and no other name', async () => {
    const h = setup((p) => (p === 'session/status' ? active(TOKEN) : items()), { token: TOKEN });
    h.c.open();
    await settle();
    assert.equal(h.sources.length, 1, 'CONTROL: the open panel opened a stream');
    assert.deepEqual(Object.keys(h.sources[0].listeners).sort(), ['closed', 'idle', 'message', 'open', 'reconnecting', 'superseded']);
    assert.equal(typeof h.sources[0].listeners.reconnecting, 'function');
    assert.equal(h.sources[0].listeners.expired, undefined, 'a listener for an event the server never sends');
});

test('W3/D6: a refused email shows the server\'s words, keeps the intro fields, and Retry uses the fix', async () => {
    const h = setup((p, body) => (p === 'availability/0' ? [200, {}]
        : p === 'session' ? (body.email === 'jane@example.com' ? active(TOKEN)
            : [400, { message: 'Please enter a valid email address.', parameters: { error_code: 'bfslc_invalid_email' } }])
            : p === 'message' ? [200, out(10, body.body)] : items()));
    h.c.open();
    await settle();
    h.c.email = 'jane@exämple.de';
    await sendText(h, 'hello');
    const msg = h.c.messages[0];
    assert.equal(msg.statusLabel, 'Not sent: Please enter a valid email address.', 'a refused email reads as a generic error');
    assert.equal(msg.canRetry, true);
    assert.equal(h.c.needsIntro, true, 'the email field went away, so it cannot be fixed');
    h.c.email = 'jane@example.com';
    h.focused.length = 0;
    h.c.message = msg;
    h.c.retry();
    await settle();
    assert.deepEqual(h.calls.filter((x) => x.path === 'session').map((x) => x.body.email), ['jane@exämple.de', 'jane@example.com']);
    assert.equal(h.c.messages[0].id, 10);
    assert.deepEqual(h.focused, ['input'], 'W10: Retry hid under focus and focus was not moved');
});

test('W9/D6: a refused text message - 5xx never in the server\'s words, a bare 4xx in the widget\'s, no answer is a connection problem', async () => {
    const cases = [
        [[500, { message: 'SQLSTATE[42S02]: Base table or view not found' }], 'Not sent: Oops'],
        [[500, { message: 'SQLSTATE[HY000] leaked', parameters: { error_code: 'bfslc_unavailable' } }], 'Not sent: Oops'],
        [[400, { message: '"sessionToken" is required.', parameters: { fieldName: 'sessionToken' } }], 'Not sent: Oops'],
        [[400, { message: 'Please type a message.', parameters: { error_code: 'bfslc_empty_body' } }], 'Not sent: Please type a message.'],
        [[0, null], 'Not sent: Offline?'],
    ];
    for (const [answer, label] of cases) {
        const h = setup((p) => (p === 'session/status' ? active(TOKEN) : p === 'message' ? answer : items()), { token: TOKEN });
        h.c.open();
        await settle();
        await sendText(h, 'x');
        assert.equal(h.c.messages[0].statusLabel, label, JSON.stringify(answer));
        assert.equal(h.c.messages[0].canRetry, true, 'CONTROL: a retry is offered');
    }
});

test('D6: a 410 resend says where the new conversation started, before the message', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN) : p === 'session' ? active(TOKEN2)
        : p === 'message' ? (body.sessionToken === TOKEN ? [410, { message: 'ended', parameters: { error_code: 'bfslc_session_closed' } }] : [200, out(20, body.body)])
            : items(inbound(3, 'old'))), { token: TOKEN });
    h.c.open();
    await settle();
    await sendText(h, 'again');
    assert.deepEqual(h.c.messages.map((m) => m.body), ['old', 'Your previous conversation had ended, so this message started a new one.', 'again']);
    assert.equal(h.c.messages[1].direction, 'system');
    assert.equal(h.c.messages[2].id, 20);
    assert.equal(h.store.bfslc_token_default, TOKEN2);
});

test('W7: a message resent after a 410 still offers Retry once the server later gives up on it', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN) : p === 'session' ? active(TOKEN2)
        : p === 'message' ? (body.sessionToken === TOKEN ? [410, { message: 'ended' }] : [200, out(21, body.body, 'pending')])
            : items()), { token: TOKEN });
    h.c.open();
    await settle();
    await sendText(h, 'again');
    const msg = h.c.messages.find((m) => m.id === 21);
    assert.ok(msg, 'CONTROL: the resend went through');
    h.sources[h.sources.length - 1].listeners.message({ data: JSON.stringify(Object.assign(out(21, 'again', 'failed'), { retrying: false })) });
    assert.equal(msg.statusLabel, 'Not delivered.');
    assert.equal(msg.canRetry, true, 'the 410 resend used up the Retry the docblock promises');
});

test('W13: no Retry for a refusal that cannot change; a 429 Retry-After keeps Retry disabled that long', async () => {
    const reader = class { readAsDataURL() { this.result = 'data:image/png;base64,AAAA'; this.onload(); } };
    const h = setup((p) => (p === 'session' ? active(TOKEN) : p === 'upload' ? [413, { message: 'x', parameters: { error_code: 'bfslc_upload_limit' } }] : items()), { FileReader: reader });
    h.c.onFileChosen({ target: { files: [{ name: 'a.png', type: 'image/png', size: 10 }], value: 'x' } });
    await settle();
    assert.equal(h.c.messages[0].statusLabel, 'Not sent: Upload limit reached', 'CONTROL: the refusal is the file limit');
    assert.equal(h.c.messages[0].canRetry, false, 'Retry offered on the conversation\'s file limit');

    let n = 0;
    const k = setup((p, body) => (p === 'session/status' ? active(TOKEN)
        : p === 'message' ? (++n === 1 ? [429, { message: 'Wait.', parameters: { error_code: 'bfslc_rate_limited' } }, { 'Retry-After': '30' }] : [200, out(30, body.body)])
            : items()), { token: TOKEN });
    k.c.open();
    await settle();
    await sendText(k, 'x');
    const msg = k.c.messages[0];
    assert.equal(msg.canRetry, true);
    assert.equal(msg.retryWaiting, true, 'Retry is clickable before the server said it can work');
    k.c.message = msg;
    k.c.retry();
    await settle();
    assert.equal(k.calls.filter((x) => x.path === 'message').length, 1, 'a waiting Retry sent');
    assert.deepEqual(k.timeoutMs().filter((ms) => ms === 30000), [30000]);
    await k.fireTimeouts(30000);
    assert.equal(msg.retryWaiting, false);
    k.c.retry();
    await settle();
    assert.equal(k.c.messages[0].id, 30);
});

test('W10: sending moves focus back to the message box; End chat too once it hides', async () => {
    const h = setup((p, body) => (p === 'session/status' ? active(TOKEN) : p === 'message' ? [200, out(5, body.body)]
        : p === 'session/close' ? [200, true] : items()), { token: TOKEN });
    h.c.open();
    await settle();
    h.focused.length = 0;
    await sendText(h, 'x');
    assert.deepEqual(h.focused, ['input'], 'Send turns disabled under focus and focus was not moved');
    h.focused.length = 0;
    h.c.endChat();
    await settle();
    assert.equal(h.c.hasSession, false, 'CONTROL: End chat is hidden now');
    assert.deepEqual(h.focused, ['input']);
});

test('W10: the log is aria-busy while the transcript loads', async () => {
    let release;
    const h = setup((p) => (p === 'session/status' ? active(TOKEN)
        : p === 'messages' ? new Promise((r) => { release = () => r(items(inbound(1, 'hi'))); }) : [500, null]), { token: TOKEN });
    assert.equal(h.c.logBusy, 'false');
    h.c.open();
    await settle();
    assert.equal(typeof release, 'function', 'CONTROL: the history request is held');
    assert.equal(h.c.logBusy, 'true');
    release();
    await settle();
    assert.equal(h.c.logBusy, 'false');
    assert.equal(h.c.messages.length, 1);
});

test('an upload type is compared case-insensitively, as Luma does', async () => {
    const reader = class { readAsDataURL() { this.result = 'data:image/png;base64,AAAA'; this.onload(); } };
    const h = setup((p, body) => (p === 'session' ? active(TOKEN) : p === 'upload' ? [200, Object.assign(out(3, ''), { attachment_name: 'A.PNG' })] : items()), { FileReader: reader });
    h.c.onFileChosen({ target: { files: [{ name: 'A.PNG', type: 'IMAGE/PNG', size: 10 }], value: 'x' } });
    await settle();
    assert.equal(h.c.notice, '', 'an upper-case type was refused');
    assert.equal(h.calls.find((x) => x.path === 'upload').body.mimeType, 'image/png');
});
