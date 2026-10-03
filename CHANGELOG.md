# Changelog — BlueFairy_SlackChatHyva

All notable changes to this package are documented in this file.

## [1.0.0] - Unreleased

### Added
- **The Hyvä chat widget** (M2MAP-135..140): a bubble and a non-modal chat panel built as one
  CSP-safe Alpine component (`bfslcChat`), talking to `bluefairy/module-slack-chat`'s REST API
  and SSE stream. Same behaviour as the base package's Luma widget:
  - nothing is fetched until the visitor opens the bubble;
  - the conversation token is kept in `localStorage` (`bfslc_token_<storeCode>`) and survives
    page loads;
  - messages appear immediately and are confirmed by the server; a refused message is marked
    **Not sent**, never shown as sent, with a Retry button unless retrying cannot help
    (`bfslc_upload_limit`), disabled for the `Retry-After` seconds after a 429 or 503;
    on 401/410 a new conversation is started, the message is sent once more, and a note says
    the previous conversation had ended;
  - agent replies arrive over Server-Sent Events, with polling every 3 s (and a fresh stream
    attempt every 60 s) when the store's stream cap is full;
  - the stream is held only while the panel is open and the tab visible; a closed panel polls
    every 30 s (for the unread badge), a hidden tab makes no requests, and reopening catches up
    from the newest message held; while polling, every 10th request checks the conversation's
    status, so a conversation closed from Slack is noticed;
  - a transient failure resuming a stored conversation is retried on the next open or send
    instead of leaving the widget disconnected; End chat reports the conversation ended only
    when the store confirms it, otherwise it shows the error and keeps the conversation;
  - every response is checked against the conversation token it was requested with, so a late
    answer for an ended conversation never touches its replacement; a 400 `bfslc_invalid_token`
    is treated like 401/410;
  - delivery wording matches the store's retrying outbox: "Sending to our team…" while pending,
    "Not delivered yet. We will keep trying for about 48 minutes." while the store retries (a
    message without the `retrying` field counts as retrying), "Not delivered." once it gives up;
    status changes re-sent over the stream are merged by id and "delivered" is final; while any
    of the visitor's messages is pending or retrying the open, visible panel re-fetches from the
    lowest such id every 30 s (for at most 60 minutes); Retry is offered on a message the store
    refused and on a given-up text message (sent again once, as a new message), never while
    the store is still retrying, so a resend can never duplicate in Slack;
  - after `event: idle` the open, visible panel polls every 60 s (the closed panel keeps its
    30 s poll) and the stream reconnects when the visitor sends, reopens or returns to the tab;
    after `event: superseded` the open panel polls every 30 s until the visitor sends from this
    tab, which takes the stream back;
  - a 4xx refusal with a known error code shows the store's own message (upload refusals name
    their cause: file limit, daily quota, storage full); a 5xx reads as the generic error;
  - ending a conversation forgets the stored token only if it is still that conversation's, so
    another tab's newer conversation survives; inputs are 16 px (no iOS zoom); name and email
    allow 120 and 255 characters, as the store does; the message list is `aria-busy` while the
    history loads; attachments show a Download button (the Luma widget previews images inline);
  - attachments are fetched with the token only from the store's own origins; an empty file is
    refused before upload; the page URL sent with a new conversation carries no query string or
    fragment;
  - file attachments (base64 JSON upload; downloads fetched with the conversation token, never
    a public URL); name and email before the first message; the availability banner;
  - accessibility: a visible focus ring on the file picker, status changes ("Not sent")
    announced by the message log, and the unread count in the bubble's accessible name.
- **One boot configuration for both storefronts**: `ViewModel\WidgetViewModel` delegates whether the
  widget shows (now also requiring Slack to be connected), the REST root (the link base, so
  `index.php` is kept when rewrites are off), the page context, the upload switch (off when no
  file type is allowed) and every label to the base package's `Model\Widget\BootConfigProvider`.
  Only the CSP nonce and the Hyvä-only `unread` label are its own.
- **`hyva_default.xml`, not `default.xml`** (M2MAP-249): the widget is added on Hyvä store views
  only, and replaces the base package's Luma widget (`bluefairy.slc.widget.luma`) there.
- **CSP**: the inline component script carries Magento's nonce, is registered with Hyvä's
  `registerInlineScript()` on theme-module 1.3.11+, and its sha256 is pinned in
  `etc/csp_whitelist.xml`. The script body is static and survives Magento's HTML minifier
  unchanged, so the hash holds in production mode.
- **Tailwind registration**: `view/frontend/tailwind/module.css` with an `@source` glob
  (Tailwind 4 themes) and `tailwind.config.js` (Tailwind 3 themes), registered through the
  `hyva_config_generate_before` observer (M2MAP-359, M2MAP-474).

### Changed in review round 2
- After the stream's `idle` end the open, visible panel kept neither stream nor poll, so a late
  reply never appeared (and the closed-panel 30 s poll stopped too). It now polls every 60 s
  while open and visible, and keeps the closed-panel poll. A reconnect after idle is answered by
  the store with `event: idle`, not 204, and the idle mark survives the reconnect.
- A superseded tab takes the stream back on its next send, as the Luma widget does.
- Ending a conversation compares before it forgets the stored token (another tab's newer token
  was erased before).
- Refusals: the store's message for a 4xx with a known error code (a 400 `bfslc_invalid_email`
  used to read as the generic error, so a visitor could not tell why the chat would not start);
  generic text for a 5xx.
- Retry: none for `bfslc_upload_limit`; disabled for the `Retry-After` seconds after a 429 or
  503; still offered on a given-up text message that had been re-sent after a 410.
- The 410 resend note, 16 px inputs, `aria-busy` during history load and the server's field
  limits (name 120, email 255), as in the Luma widget.
- README: the Private Packagist repository and `auth.json` step before `composer require`, and
  the behaviour above.
- Release gate: `.githooks/pre-push` also runs the widget's JavaScript suite
  (`node --test Test/Js/*.test.cjs`) on a tag push. The planted-red control is recorded here
  once it has been run.

### Changed in review round 2b
- The stream listener for `expired` is gone: the store ends a stream whose slot could not be
  renewed with `event: reconnecting` only (`StreamRunner::EVENT_RECONNECTING`), and the widget
  now listens for exactly the events the store sends. A test pins the listener list.
- `etc/csp_whitelist.xml`: the script's sha256 is now
  `qBePWP5d+VF9qoDTrud5Qrhu3Jz0I5yI0bHlUFHicx8=` (the script changed).
- README: the script is about 49 KB (about 9 KB gzipped), measured from the template; the
  `reconnecting` ending, the 503 wording, the no-Retry file refusals and the Luma widget's
  attachment handling described as they are.

### Development (not in the package a merchant installs)
- Unit suite: view model (boot-config key set, nothing per visitor, excluded pages, nonce),
  observer and its subscription, layout (hyva_-only, adds the Hyvä block, removes the Luma one),
  CSP hash (rendered body, Hyvä's own extractor, the real Magento minifier, mutation controls),
  and the package checks ported from module-jira-hyva (release gate, archive, licence, Tailwind
  scan - with the widened comment stripper and a globstar-aware matcher from M2MAP-474).
- `Test/Js/chat-behaviour.test.cjs`: the Alpine component's behaviour under plain `node --test`
  (fake fetch, timers, EventSource, visibility), each guard shown red by a mutation.
- The layout test reads the base package from MAGENTO_ROOT's vendor/ as well as a sibling
  checkout, and fails when there is none unless `BFSLC_ALLOW_STANDALONE=1`.
- Gate configs: `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist` (level 6, PHP 8.3),
  `Test/bootstrap.php` (reads the base package from a sibling checkout; keeps the M2MAP-682
  refusal).
