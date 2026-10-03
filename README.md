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
Slack Live Chat → Advanced → Show the Chat Widget** is Yes for that store view. On a Luma store view
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
- One inline script, about 31 KB uncompressed (about 6 KB gzipped), parsed but idle until
  Alpine starts. No external JavaScript, no web fonts, no images (the icons are inline SVG).
- The bubble and panel are `position: fixed`, so they cause no layout shift.
- The bubble has an accessible name, the panel is a labelled non-modal dialog, and new
  messages are announced through a polite live region.

## Troubleshooting

| Symptom | Cause |
| :--- | :--- |
| The panel appears unstyled at the foot of the page | The theme CSS was not rebuilt after install (see above). |
| No bubble at all | Widget disabled for the store view, or you are on the checkout/success page, or the store view uses a Luma theme. |
| Bubble shows but does nothing; console shows a CSP error | A custom CSP header replaced Magento's. Allow the hash in `etc/csp_whitelist.xml`, or Magento's nonce. |
| Replies arrive with a few seconds' delay | The base package's concurrent-stream cap is full; the widget is polling every 3 seconds and will retry the stream every 60. |

## License

Proprietary. Copyright © 2026 Blue Fairy Development. All rights reserved. See `LICENSE`.
