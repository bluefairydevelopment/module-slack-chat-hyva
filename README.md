# BlueFairy Slack Live Chat — Hyvä

The Hyvä storefront widget for **BlueFairy Slack Live Chat** (`bluefairy/module-slack-chat`):
a chat bubble that opens a conversation your team answers in Slack. Built as one Alpine.js
component that works under Hyvä's strict-CSP Alpine build, styled with Tailwind utilities.

The base package does all the work - sessions, Slack, uploads, the reply stream. This package
only renders the widget on Hyvä store views, and replaces the base package's Luma widget there.

## Requirements

| | |
| :--- | :--- |
| Magento Open Source / Adobe Commerce | 2.4.8 or 2.4.9 |
| PHP | 8.3, 8.4 or 8.5 |
| `bluefairy/module-slack-chat` | 1.x |
| `hyva-themes/magento2-theme-module` | 1.3 or later, on a theme using Alpine v3 |

## Install

### 1. Add the Private Packagist repository

The BlueFairy Slack Live Chat packages are distributed **exclusively through Private
Packagist**. They are not on public Packagist, so `composer require` cannot find them until the
repository and your credentials are configured. Run this first, once per Magento install, in the
project root:

```bash
composer config repositories.bluefairy composer \
    https://bluefairy.repo.packagist.com/<your-repository-slug>/
composer config --auth http-basic.bluefairy.repo.packagist.com \
    <your-username> <your-password>
```

`composer config --auth` writes `auth.json` in the project root. If you prefer to create it by
hand, it takes this shape:

```json
{
    "http-basic": {
        "bluefairy.repo.packagist.com": {
            "username": "<your-username>",
            "password": "<your-password>"
        }
    }
}
```

Your repository slug, username and password are in the credentials email sent with your
purchase. Keep `auth.json` out of version control.

### 2. Require and enable the package

`composer require` brings in `bluefairy/module-slack-chat` as well. Set that package up (Slack
app, credentials, channel) as its README describes; this one adds only the storefront widget.

```bash
composer require bluefairy/module-slack-chat-hyva
bin/magento module:enable BlueFairy_SlackChatHyva
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode only
bin/magento hyva:config:generate
```

### Rebuild the theme CSS - this step is not optional

Hyvä compiles one stylesheet per theme from the classes it finds. Until the theme is rebuilt
after installing (or upgrading) this package, the widget's classes are not in that stylesheet
and the chat panel renders unstyled and unpositioned at the bottom of the page - with no error
anywhere. In the theme's Tailwind directory:

```bash
cd app/design/frontend/<Vendor>/<theme>/web/tailwind
npm run build
```

Then deploy static content (production mode) and flush the cache:

```bash
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## Where the widget appears

On every page of a Hyvä store view **except** the checkout (`checkout_index_index`) and the
order success page (`checkout_onepage_success`), when **Stores → Configuration → BlueFairy Integrations →
Slack Live Chat → Advanced → Show the Chat Widget** is Yes for that store view **and Slack is
connected** (a bot token or an incoming webhook) **and**, with a bot token, the store view has a
**Default Channel ID** - a bubble that could only ever fail to start a conversation is not shown.
Keep the widget off until **Test Connection** passes and a test conversation has made the round
trip. The **Attach a file** link appears only when uploads are on and at
least one file type is allowed. These rules, the page context below and every label are the base
package's (`BlueFairy\SlackChat\Model\Widget\BootConfigProvider`), shared with the Luma widget,
so the two storefronts behave the same. On a Luma store view
nothing in this package applies - the layout file is `hyva_default.xml`, which Hyvä only loads
for Hyvä themes - and the base package's Luma widget is shown instead.

Conversations opened from a product, category or CMS page are routed to the Slack channel
mapped to that page (base package channel mapping). The home page uses the default channel.

## Content Security Policy

The widget adds one inline `<script>`, and it is allowed three ways, so it works whether
`Magento_Csp` runs in report-only or restrict mode:

1. it carries Magento's CSP nonce (`Magento\Csp\Helper\CspNonceProvider`);
2. on Hyvä theme-module 1.3.11 or later it is registered with Hyvä's
   `$hyvaCsp->registerInlineScript()`, which adds its hash (full-page-cached pages) or a nonce;
3. its sha256 is listed in this package's `etc/csp_whitelist.xml`.

The script contains no per-page data (that travels in a `data-` attribute), so its hash is the
same on every page and is not changed by Magento's HTML minifier. All requests go to your own
store (`connect-src 'self'`); the widget loads nothing from another host.

The component is written for Alpine's CSP build, which Hyvä serves when the policy has no
`'unsafe-eval'`: no inline expressions, no `x-model`, no `x-html`. Visitor and agent text is
always inserted as text, never as HTML.

## Performance (Lighthouse)

- **No network requests until the visitor opens the bubble.** Opening it starts the
  conversation lookup and the reply stream; a page where nobody chats makes no chat requests.
- **The reply stream is held only while the panel is open and the tab is visible.** Each open
  stream occupies one PHP worker for its whole life, so the widget closes it when the visitor
  closes the panel or switches tab. With the panel closed (and a conversation in progress) the
  widget instead asks for new messages **every 30 seconds** - that is what keeps the unread
  badge on the bubble current - and with the tab hidden it makes no requests at all. That
  closed-panel check starts only once the visitor has opened the bubble on the page: on a page
  where it was never opened nothing is requested, so a reply that arrives meanwhile shows (with
  no badge before) when the bubble is opened. Reopening
  the panel reconnects the stream from the newest message already shown, so nothing is missed.
  While polling, every tenth request also checks whether the conversation has been closed.
- **Delivery statuses settle on their own.** The store retries a message Slack refused for
  about 48 minutes, so while one of the visitor's messages reads "Sending to our team…" or
  "Not delivered yet. We will keep trying for about 48 minutes.", the open panel re-checks it
  every 30 seconds (for at most 60 minutes, never while closed or hidden) until it settles.
  Such a message offers no Retry: the store is already retrying, and a second copy would reach
  Slack twice. Retry is offered on a message the store refused outright ("Not sent"), and on a
  text message the store has given up on ("Not delivered."), which is sent again as a new one -
  also when that message had itself been re-sent into a new conversation. No Retry is offered
  when retrying cannot help (`bfslc_upload_limit`: the conversation already holds all the files
  it may), nor on a file the store refused for itself (`bfslc_file_too_large`, `bfslc_bad_mime`,
  `bfslc_file_empty`). After a 429 or 503 refusal the Retry button stays disabled for as many
  seconds as the store's `Retry-After` header asks.
- **Refusals say why.** A refusal the store explains (a 4xx answer with a known error code, for
  example `bfslc_invalid_email` for an address it does not accept) shows the store's own message;
  a 503 reads "Chat is unavailable right now." and any other server error (5xx) the generic
  "Something went wrong. Please try again." text. The name and email fields allow what the
  store allows: 120 and 255 characters.
- **A conversation that ended is restarted, and the visitor is told.** A message sent into a
  conversation that has meanwhile ended (401/410) starts a new one and is sent there once, with
  the note "Your previous conversation had ended, so this message started a new one."
- **A stream can end.** After 30 minutes with nothing said the store ends the stream (`idle`;
  a reconnect after that is answered at once with the same `idle` event, never refused). From
  then on an open panel in a visible tab asks for new messages **every 60 seconds**, so a late
  reply from the team still arrives, and the closed panel keeps its 30-second check for the
  unread badge. A check that brings a new message reconnects the stream at once, and so do
  sending, reopening the panel or returning to the tab. When the store ends a stream with
  `reconnecting` (its slot could not be renewed), the widget reconnects at once from the newest
  message held; a stream that ends that way within 10 seconds of opening is treated as refused
  (3-second checks, the stream tried again after 60 seconds). If the same conversation is open in another tab, the newer tab takes the stream
  (`superseded`) and this one polls every 30 seconds while its panel is open, until the visitor
  sends from it: then it takes the stream back. If one visitor address already holds three live
  streams (an office or school behind one address), a further tab or visitor gets none: its
  widget checks every 3 seconds and tries the stream again later. Nobody's open stream is cut
  off.
- **The transcript is complete and in order.** A conversation's history is always loaded from
  its first message, and every message is placed by its id - also when the visitor sent a
  message before the history arrived (after a failed resume). A message an agent edits or
  deletes in Slack shows its new text, or "(This message was removed.)", the next time the
  history loads (reopening the chat, reloading the page), not live.
- **A signed-in customer's conversation stays theirs.** After they sign out, the next person at
  the same browser cannot read or continue it: the store answers 401, and the widget forgets the
  token and starts afresh.
- **One file at a time.** A file sent while another of the same conversation is still uploading
  is refused with "Another file is still uploading. Please try again in a moment." (429
  `bfslc_upload_busy`) and offers Retry, disabled for the 10 seconds the store asks.
- **One tab's ending never touches another's conversation.** Ending a conversation forgets the
  stored token only if it is still that conversation's, so a newer conversation started in
  another tab is kept.
- One inline script, about 50 KB uncompressed (about 9 KB gzipped), parsed but idle until
  Alpine starts. No external JavaScript, no web fonts, no images (the icons are inline SVG).
- The bubble and panel are `position: fixed`, so they cause no layout shift.
- The bubble has an accessible name that includes the unread count, the panel is a labelled
  non-modal dialog, and new messages - and a message's status changing, such as "Not sent" -
  are announced through a polite live region. The message list is marked `aria-busy` while the
  conversation's history loads. Every control, the file picker included, shows a visible
  keyboard focus ring, and the message list itself is a tab stop (with the same ring) so it can
  be scrolled from the keyboard. Inputs use a 16 px font, so iOS Safari does not zoom the page when one
  is focused.
- **Attachments differ from the Luma widget on purpose.** A file in the conversation shows a
  **Download** button that fetches it only when clicked, with the token of the conversation the
  file belongs to - so it still downloads after the visitor ended that conversation or started a
  new one; nothing is downloaded until the visitor asks. The Luma widget shows images inline, fetching each when
  it scrolls into view, and other files as the same kind of button. The base package's README
  lists every remaining difference between the two widgets.

## Troubleshooting

| Symptom | Cause |
| :--- | :--- |
| The panel appears unstyled at the foot of the page | The theme CSS was not rebuilt after install (see above). |
| No bubble at all | Widget disabled for the store view, or you are on the checkout/success page, or the store view uses a Luma theme. |
| Bubble shows but does nothing; console shows a CSP error | A custom CSP header replaced Magento's. Allow the hash in `etc/csp_whitelist.xml`, or Magento's nonce. |
| Replies arrive with a few seconds' delay | The base package's concurrent-stream cap is full, or the visitor's address already holds three streams; the widget is polling every 3 seconds and will retry the stream every 60. |
| No unread badge on a page where the bubble was never opened | Expected: nothing is requested until the bubble is first opened on a page. |
| After a long quiet spell a reply takes up to a minute to appear | Expected: after 30 minutes with no message the stream ends (`idle`) and the open panel checks every 60 seconds until the visitor sends or reopens it. |
| The unread badge takes up to half a minute to appear | Expected: with the panel closed the widget checks every 30 seconds rather than holding a stream open (see Performance). |
| A message reads "Not delivered yet. We will keep trying for about 48 minutes." | The store saved it but Slack refused it or could not be reached. The store retries for about 48 minutes; check the Slack credentials and the cron. |
| A message reads "Not delivered." | The store's retries ran out. The visitor can press Retry to send it again. |

## License

Proprietary. Copyright © 2026 Blue Fairy Development. All rights reserved. See `LICENSE`.
