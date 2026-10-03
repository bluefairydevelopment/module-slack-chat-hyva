<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\ViewModel;

use BlueFairy\SlackChat\Model\Config;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Everything the Hyvä chat widget needs at render time.
 *
 * THE WIDGET IS RENDERED INTO EVERY FULL-PAGE-CACHED PAGE. So nothing here may
 * depend on who is looking: no session token, no customer, no availability (it
 * changes with the clock and is fetched by the widget when it is opened), no
 * form key. What it returns depends only on the store, the page URL and config -
 * which is exactly what the page cache already keys on.
 *
 * The visitor's own state (their conversation token) lives in localStorage and
 * is read by the browser, never by PHP.
 */
class WidgetViewModel implements ArgumentInterface
{
    /**
     * Pages the widget never appears on. A chat bubble over the payment step
     * covers the place-order button on narrow screens and invites a visitor to
     * paste card details into a channel that is not built for them; on the
     * success page the order is done.
     */
    public const EXCLUDED_ACTIONS = [
        'checkout_index_index',
        'checkout_onepage_success',
    ];

    /** The REST service prefix every visitor route shares (base etc/webapi.xml). */
    private const SERVICE_PATH = 'V1/bluefairy-slack-chat/';

    /** The SSE stream: a frontend controller, not a webapi route (EventSource can only GET). */
    private const STREAM_ROUTE = 'bluefairy_slc/stream/index';

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param HttpRequest $request
     * @param UrlInterface $url
     * @param CspNonceProvider $cspNonceProvider Present on every Magento this package admits:
     *        magento/module-csp 100.4.7 (2.4.8) ships Magento\Csp\Helper\CspNonceProvider with
     *        generateNonce(): string, as does 100.4.8 (2.4.9). It is therefore a plain typed
     *        dependency, not an optional one - an optional class would need the ObjectManager.
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request,
        private readonly UrlInterface $url,
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
        if (in_array($this->request->getFullActionName(), self::EXCLUDED_ACTIONS, true)) {
            return false;
        }

        return $this->config->isWidgetEnabled($this->storeId());
    }

    /**
     * The boot configuration the Alpine component reads from its data attribute.
     *
     * Store- and page-scoped only (see the class comment). The template encodes it
     * with json_encode and escapeHtmlAttr; nothing here is pre-escaped.
     *
     * @return array<string, mixed>
     */
    public function getBootConfig(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $storeCode = (string) $store->getCode();
        [$productId, $categoryId, $cmsPageId] = $this->pageContext();

        return [
            // The link base URL, as Hyvä's own BASE_URL is ($block->getUrl('/') in
            // Hyva_Theme::page/js/variables.phtml): it carries index.php when rewrites
            // are off and the store code when it is in the URL, and Magento strips
            // that prefix before choosing the webapi area.
            'restRoot' => $this->url->getBaseUrl() . 'rest/' . rawurlencode($storeCode) . '/' . self::SERVICE_PATH,
            'streamUrl' => $this->url->getUrl(self::STREAM_ROUTE, ['_nosid' => true]),
            'storeId' => $storeId,
            'storeCode' => $storeCode,
            'productId' => $productId,
            'categoryId' => $categoryId,
            'cmsPageId' => $cmsPageId,
            'uploadsEnabled' => $this->config->isUploadEnabled($storeId),
            'maxUploadBytes' => $this->config->getUploadMaxBytes($storeId),
            'allowedMimeTypes' => $this->config->getAllowedMimeTypes($storeId),
            'i18n' => $this->phrases(),
        ];
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
     * Product, category and CMS page ids for channel routing, from the route.
     *
     * Only the page types the base ChannelResolver maps. The home page
     * (cms_index_index) carries no page_id parameter and is left to the default
     * channel rather than looked up here.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function pageContext(): array
    {
        switch ($this->request->getFullActionName()) {
            case 'catalog_product_view':
                return [$this->positiveParam('id'), $this->positiveParam('category'), null];
            case 'catalog_category_view':
                return [null, $this->positiveParam('id'), null];
            case 'cms_page_view':
                return [null, null, $this->positiveParam('page_id')];
            default:
                return [null, null, null];
        }
    }

    /**
     * A request parameter as a positive integer, or null.
     *
     * @param string $name
     * @return int|null
     */
    private function positiveParam(string $name): ?int
    {
        $value = $this->request->getParam($name);
        if (!is_scalar($value) || !preg_match('/^[1-9]\d{0,9}$/', (string) $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }

    /**
     * Visitor-facing strings, translated for the store's locale.
     *
     * Rendered by the widget with x-text only, never as HTML.
     *
     * @return array<string, string>
     */
    private function phrases(): array
    {
        return [
            'title' => (string) __('Chat with us'),
            'open' => (string) __('Open chat'),
            'close' => (string) __('Close chat'),
            'intro' => (string) __('Ask us anything. We reply right here.'),
            'nameLabel' => (string) __('Your name (optional)'),
            'emailLabel' => (string) __('Your email (optional)'),
            'messageLabel' => (string) __('Message'),
            'placeholder' => (string) __('Type your message...'),
            'send' => (string) __('Send'),
            'attach' => (string) __('Attach a file'),
            'you' => (string) __('You'),
            'agent' => (string) __('Support'),
            'system' => (string) __('Notice'),
            'sending' => (string) __('Sending...'),
            'notSent' => (string) __('Not sent'),
            'notDelivered' => (string) __('Received, but our team has not been notified yet.'),
            'retry' => (string) __('Retry'),
            'download' => (string) __('Download'),
            'endChat' => (string) __('End chat'),
            'ended' => (string) __('This conversation has ended. Send a message to start a new one.'),
            'rateLimited' => (string) __('You are sending messages too quickly. Please wait a moment.'),
            'unavailable' => (string) __('Chat is unavailable right now.'),
            'tooLarge' => (string) __('That file is too large.'),
            'typeNotAllowed' => (string) __('That type of file cannot be sent.'),
            'uploadsOff' => (string) __('File uploads are turned off.'),
            'genericError' => (string) __('Something went wrong. Please try again.'),
        ];
    }
}
