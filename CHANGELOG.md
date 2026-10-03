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
  - messages appear immediately and are confirmed by the server; a message refused with 429,
    503 or any other error is marked **Not sent** with a Retry button, never shown as sent;
    on 401/410 a new conversation is started and the message is sent once more;
  - agent replies arrive over Server-Sent Events, with polling every 3 s (and a fresh stream
    attempt every 60 s) when the store's stream cap is full;
  - file attachments (base64 JSON upload; downloads fetched with the conversation token, never
    a public URL); name and email before the first message; the availability banner.
- **`hyva_default.xml`, not `default.xml`** (M2MAP-249): the widget is added on Hyvä store views
  only, and replaces the base package's Luma widget (`bluefairy.slc.widget.luma`) there.
- **CSP**: the inline component script carries Magento's nonce, is registered with Hyvä's
  `registerInlineScript()` on theme-module 1.3.11+, and its sha256 is pinned in
  `etc/csp_whitelist.xml`. The script body is static and survives Magento's HTML minifier
  unchanged, so the hash holds in production mode.
- **Tailwind registration**: `view/frontend/tailwind/module.css` with an `@source` glob
  (Tailwind 4 themes) and `tailwind.config.js` (Tailwind 3 themes), registered through the
  `hyva_config_generate_before` observer (M2MAP-359, M2MAP-474).

### Development (not in the package a merchant installs)
- Unit suite: view model (boot-config key set, nothing per visitor, excluded pages, nonce),
  observer and its subscription, layout (hyva_-only, adds the Hyvä block, removes the Luma one),
  CSP hash (rendered body, Hyvä's own extractor, the real Magento minifier, mutation controls),
  and the package checks ported from module-jira-hyva (release gate, archive, licence, Tailwind
  scan - with the widened comment stripper and a globstar-aware matcher from M2MAP-474).
- Gate configs: `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist` (level 6, PHP 8.3),
  `Test/bootstrap.php` (reads the base package from a sibling checkout; keeps the M2MAP-682
  refusal).
