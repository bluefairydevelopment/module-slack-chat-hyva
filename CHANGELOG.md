# Changelog — BlueFairy_SlackChatHyva

All notable changes to this package are documented in this file.

## [1.0.0] - Unreleased

First release: the Hyvä storefront widget for BlueFairy Slack Live Chat
(`bluefairy/module-slack-chat`).

### The widget
- A chat bubble and a non-modal chat panel, built as one Alpine component (`bfslcChat`) that works
  under Hyvä's strict-CSP Alpine build and is styled with Tailwind utilities. It talks to the base
  package's REST API and live stream and behaves as the base package's Luma widget does.
- Nothing is requested until the visitor opens the bubble. The conversation token is kept in
  `localStorage` (`bfslc_token_<storeCode>`) and survives page loads; a signed-in customer's
  conversation answers only to that customer.
- Shown on every page of a Hyvä store view except the checkout and the order success page, when
  the widget is switched on, Slack is connected and (with a bot token) the store view has a
  Default Channel ID. On a Luma store view nothing of this package applies.
- Messages appear at once and are confirmed by the store. A refused message reads **Not sent**
  with the store's own reason for a known refusal (upload refusals name their cause: file limit,
  daily quota, storage full, another file still uploading), and a Retry button where retrying can
  help, held back for the store's Retry-After. A message sent into a conversation that has ended
  starts a new one, once, with a note saying so.
- Agent replies arrive over Server-Sent Events while the panel is open and the tab visible, with
  3-second polling when the stream is refused. With the panel closed (once the bubble has been
  opened on the page) the widget checks every 30 seconds and shows an unread count on the bubble;
  a hidden tab makes no requests. After 30 minutes of silence the open panel checks every 60
  seconds; a newer tab of the same conversation takes the stream and the older one checks every
  30 seconds until the visitor sends from it.
- The transcript is loaded from its first message and kept in id order. Agent edits and
  deletions in Slack show at the next history load.
- Delivery wording follows the store's retrying outbox: "Sending to our team…", "Not delivered
  yet. We will keep trying for about 48 minutes.", and "Not delivered." with Retry once the store
  gives up; an unsettled message is re-checked every 30 seconds while the panel is open.
- Attachments: a Download button per file, fetched only when clicked, with the token of the
  conversation the file belongs to and only from the store's own origins.
- Accessibility: the bubble's accessible name carries the unread count, new messages and status
  changes are announced, the message list is `aria-busy` while history loads and is itself a tab
  stop, every control shows a keyboard focus ring, and inputs are 16 px.

### Platform
- One boot configuration for both storefronts: whether the widget shows, the REST root, the page
  context, the upload switch and every label come from the base package's
  `Model\Widget\BootConfigProvider`; only the CSP nonce and the Hyvä-only `unread` label are this
  package's own.
- `hyva_default.xml`, not `default.xml`: the widget is added on Hyvä store views only and replaces
  the base package's Luma widget there.
- Content Security Policy: the one inline script carries Magento's nonce, is registered with
  Hyvä's `registerInlineScript()` on theme-module 1.3.11+, and its sha256 is listed in
  `etc/csp_whitelist.xml` (`PI5PylDNH8RBsQcv/KcVtRAjgoA+liwxRf3po6DSpVc=`). The script body is
  static and survives Magento's HTML minifier, so the hash holds in production mode.
- Tailwind registration: `view/frontend/tailwind/module.css` with an `@source` glob (Tailwind 4
  themes) and `tailwind.config.js` (Tailwind 3 themes), registered through the
  `hyva_config_generate_before` observer. The theme's CSS must be rebuilt after install.
