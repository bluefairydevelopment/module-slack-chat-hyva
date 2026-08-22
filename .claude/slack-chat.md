# BlueFairy Slack Live Chat — Hyva module notes

**Authoritative plan:** `~/Sites/blueSites/plans/bluefairy-slack-live-chat-magento.plan.md` — see §8 (Hyva Module Design), §9 finding A4 (CSP nonce), and milestone M11.

## Rules
- Zero RequireJS, zero Knockout. Alpine.js only.
- Inline `<script>` MUST carry a nonce via `Magento\Csp\Helper\CspNonceProvider`.
- Consumes core REST endpoints; no duplicate business logic.
