<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\ViewModel;

use BlueFairy\SlackChat\Model\Widget\BootConfigProvider;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Everything the Hyvä chat widget needs at render time.
 *
 * THE DECISIONS ARE THE BASE PACKAGE'S. Whether the widget shows on this page,
 * the REST root, the page context used for channel routing, whether uploads are
 * offered and every label come from BlueFairy\SlackChat\Model\Widget\BootConfigProvider,
 * which the Luma widget asks too. The two view models had already drifted once
 * (this one did not check that Slack was configured, nor turn uploads off for an
 * empty type list); now there is nothing here to drift. What stays here is only
 * what is Hyvä's own: the CSP nonce for the inline component script, and the one
 * label only this template uses.
 *
 * THE WIDGET IS RENDERED INTO EVERY FULL-PAGE-CACHED PAGE. So nothing here may
 * depend on who is looking: no session token, no customer, no availability, no
 * form key - and no nonce in the boot config (see getCspNonce()).
 *
 * The visitor's own state (their conversation token) lives in localStorage and
 * is read by the browser, never by PHP.
 */
class WidgetViewModel implements ArgumentInterface
{
    /**
     * @param BootConfigProvider $bootConfigProvider
     * @param CspNonceProvider $cspNonceProvider Present on every Magento this package admits:
     *        magento/module-csp 100.4.7 (2.4.8) ships Magento\Csp\Helper\CspNonceProvider with
     *        generateNonce(): string, as does 100.4.8 (2.4.9). It is therefore a plain typed
     *        dependency, not an optional one - an optional class would need the ObjectManager.
     */
    public function __construct(
        private readonly BootConfigProvider $bootConfigProvider,
        private readonly CspNonceProvider $cspNonceProvider
    ) {
    }

    /**
     * Whether to render the widget at all on this page.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->bootConfigProvider->isEnabled();
    }

    /**
     * The boot configuration the Alpine component reads from its data attribute:
     * the base package's, plus the labels only the Hyvä template uses.
     *
     * The template encodes it with json_encode and escapeHtmlAttr; nothing here is
     * pre-escaped.
     *
     * @return array<string, mixed>
     */
    public function getBootConfig(): array
    {
        $boot = $this->bootConfigProvider->getBootConfig();
        // The base labels win on any shared key: one translation covers both widgets.
        $boot['i18n'] = $boot['i18n'] + $this->hyvaLabels();

        return $boot;
    }

    /**
     * The CSP nonce for the widget's one inline script, or '' if none can be made.
     *
     * Calling this adds the nonce to this response's script-src. Magento renders
     * nonces only when the policy does NOT allow 'unsafe-inline'
     * (Magento\Csp\Model\Policy\FetchPolicy::getValue), so on a permissive store it
     * cannot switch 'unsafe-inline' off for everyone else's inline scripts.
     *
     * @return string
     */
    public function getCspNonce(): string
    {
        try {
            return $this->cspNonceProvider->generateNonce();
        } catch (LocalizedException $e) {
            // Random::getRandomString() throws only when the system has no CSPRNG.
            // An empty nonce attribute matches nothing, so the script then runs only
            // where the policy allows it some other way (the hash in csp_whitelist.xml,
            // Hyvä's registerInlineScript, or 'unsafe-inline') - never a weaker nonce.
            return '';
        }
    }

    /**
     * Labels the Hyvä template uses and the base set does not carry.
     *
     * Rendered by the widget with x-text / aria-label only, never as HTML.
     *
     * @return array<string, string>
     */
    private function hyvaLabels(): array
    {
        return [
            // The unread count, spoken in the bubble's accessible name and a polite
            // status region; %1 is replaced in the browser with the number.
            'unread' => (string) __('%1 unread'),
        ];
    }
}
