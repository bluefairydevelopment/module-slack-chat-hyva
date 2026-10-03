<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\ViewModel;

use BlueFairy\SlackChat\Model\Config;
use BlueFairy\SlackChatHyva\ViewModel\WidgetViewModel;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The widget is rendered into every full-page-cached page, so the boot config is
 * a cache-safety boundary as much as a feature: what is in it is what every
 * visitor of that URL receives.
 */
class WidgetViewModelTest extends TestCase
{
    private const NONCE = 'bm9uY2Utdmlld21vZGVs';

    /** @var Config&Stub */
    private Config $config;

    private StoreManagerInterface $storeManager;

    private HttpRequest $request;

    private UrlInterface $url;

    /** Replaced by a mock in the tests that set expectations on it. */
    private CspNonceProvider $nonceProvider;

    /** @var array<string, mixed> */
    private array $params = [];

    private string $action = 'cms_index_index';

    protected function setUp(): void
    {
        // __() needs a renderer; the default one returns the text unchanged.
        Phrase::setRenderer(new \Magento\Framework\Phrase\Renderer\Placeholder());

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCode')->willReturn('fr_ca');

        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->request = $this->createStub(HttpRequest::class);
        $this->request->method('getFullActionName')->willReturnCallback(fn (): string => $this->action);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $name, $default = null) => $this->params[$name] ?? $default
        );

        $this->url = $this->createStub(UrlInterface::class);
        $this->url->method('getBaseUrl')->willReturn('https://shop.example/');
        $this->url->method('getUrl')->willReturnCallback(
            static fn ($route = null, $params = null): string => 'https://shop.example/' . $route . '/'
                . ((is_array($params) && ($params['_nosid'] ?? false) === true) ? '' : '?SID=leak')
        );

        $this->config = $this->createStub(Config::class);
        $this->config->method('isUploadEnabled')->willReturnCallback(static fn (?int $id): bool => $id === 3);
        $this->config->method('getUploadMaxBytes')->willReturnCallback(static fn (?int $id): int => $id === 3 ? 5242880 : 1);
        $this->config->method('getAllowedMimeTypes')->willReturnCallback(
            static fn (?int $id): array => $id === 3 ? ['image/png', 'application/pdf'] : []
        );

        $this->nonceProvider = $this->createStub(CspNonceProvider::class);
    }

    private function subject(): WidgetViewModel
    {
        return new WidgetViewModel($this->config, $this->storeManager, $this->request, $this->url, $this->nonceProvider);
    }

    public function testTheBootConfigHasExactlyTheDocumentedKeys(): void
    {
        $boot = $this->subject()->getBootConfig();

        self::assertSame(
            [
                'restRoot', 'streamUrl', 'storeId', 'storeCode', 'productId', 'categoryId', 'cmsPageId',
                'uploadsEnabled', 'maxUploadBytes', 'allowedMimeTypes', 'i18n',
            ],
            array_keys($boot)
        );
        self::assertSame(
            [
                'title', 'open', 'close', 'intro', 'nameLabel', 'emailLabel', 'messageLabel', 'placeholder',
                'send', 'attach', 'you', 'agent', 'system', 'sending', 'notSent', 'notDelivered', 'retry',
                'download', 'endChat', 'ended', 'rateLimited', 'unavailable', 'tooLarge', 'typeNotAllowed',
                'uploadsOff', 'genericError',
            ],
            array_keys($boot['i18n'])
        );
    }

    public function testTheBootConfigValuesComeFromTheStoreAndConfig(): void
    {
        $boot = $this->subject()->getBootConfig();

        self::assertSame('https://shop.example/rest/fr_ca/V1/bluefairy-slack-chat/', $boot['restRoot']);
        // _nosid: a session id in a cached page would be handed to every visitor.
        self::assertSame('https://shop.example/bluefairy_slc/stream/index/', $boot['streamUrl']);
        self::assertSame(3, $boot['storeId']);
        self::assertSame('fr_ca', $boot['storeCode']);
        self::assertTrue($boot['uploadsEnabled']);
        self::assertSame(5242880, $boot['maxUploadBytes']);
        self::assertSame(['image/png', 'application/pdf'], $boot['allowedMimeTypes']);
        self::assertSame('Chat with us', $boot['i18n']['title']);
    }

    /**
     * NOTHING PER VISITOR. The boot config is encoded into a cached page. The check
     * is on the encoded form, against values a per-visitor leak would carry, and
     * is shown able to see such a value by planting one.
     */
    public function testTheBootConfigCarriesNothingPerVisitor(): void
    {
        $encoded = (string) json_encode($this->subject()->getBootConfig());

        self::assertStringContainsString('"storeCode":"fr_ca"', $encoded, 'CONTROL: the haystack is the boot config');
        foreach (['token', 'session', 'SID', 'form_key', 'formKey', 'customer', 'email":', 'nonce', self::NONCE] as $needle) {
            self::assertStringNotContainsString($needle, $encoded, "the boot config carries '{$needle}'");
        }
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $encoded, 'a 64-hex value (a session token) is in the boot config');

        // The plant: the same check over a config that DID carry a token goes red.
        $planted = (string) json_encode(['sessionToken' => str_repeat('a', 64)]);
        self::assertStringContainsString('session', $planted);
        self::assertMatchesRegularExpression('/[a-f0-9]{64}/', $planted);
    }

    public function testBuildingTheBootConfigNeverMakesANonce(): void
    {
        // A nonce is per response; if it reached the boot config it would be cached.
        $nonces = $this->createMock(CspNonceProvider::class);
        $nonces->expects(self::never())->method('generateNonce');
        $this->nonceProvider = $nonces;

        $this->subject()->getBootConfig();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array{0: ?int, 1: ?int, 2: ?int}}>
     */
    public static function pageContexts(): array
    {
        return [
            'product page' => ['catalog_product_view', ['id' => '42'], [42, null, null]],
            'product page reached from a category' => ['catalog_product_view', ['id' => '42', 'category' => '7'], [42, 7, null]],
            'category page' => ['catalog_category_view', ['id' => '7'], [null, 7, null]],
            'cms page' => ['cms_page_view', ['page_id' => '5'], [null, null, 5]],
            'home page' => ['cms_index_index', ['page_id' => '5'], [null, null, null]],
            'search results ignore an id param' => ['catalogsearch_result_index', ['id' => '42'], [null, null, null]],
            'non-numeric id' => ['catalog_product_view', ['id' => '42abc'], [null, null, null]],
            'zero id' => ['catalog_product_view', ['id' => '0'], [null, null, null]],
            'negative id' => ['catalog_category_view', ['id' => '-3'], [null, null, null]],
            'array id' => ['catalog_product_view', ['id' => ['42']], [null, null, null]],
        ];
    }

    /**
     * @dataProvider pageContexts
     * @param string $action
     * @param array<string, mixed> $params
     * @param array{0: ?int, 1: ?int, 2: ?int} $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageContexts')]
    public function testThePageContextComesFromTheRoute(string $action, array $params, array $expected): void
    {
        $this->action = $action;
        $this->params = $params;

        $boot = $this->subject()->getBootConfig();

        self::assertSame($expected, [$boot['productId'], $boot['categoryId'], $boot['cmsPageId']]);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function enabledCases(): array
    {
        return [
            'enabled on a product page' => ['catalog_product_view', true, true],
            'disabled in config' => ['catalog_product_view', false, false],
            'never on the checkout' => ['checkout_index_index', true, false],
            'never on the success page' => ['checkout_onepage_success', true, false],
            'cart is not excluded' => ['checkout_cart_index', true, true],
        ];
    }

    /**
     * @dataProvider enabledCases
     * @param string $action
     * @param bool $configEnabled
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enabledCases')]
    public function testIsEnabled(string $action, bool $configEnabled, bool $expected): void
    {
        $this->action = $action;
        // Keyed on the store id, so reading another store's flag cannot pass.
        $this->config->method('isWidgetEnabled')->willReturnCallback(
            static fn (?int $id): bool => $id === 3 && $configEnabled
        );

        self::assertSame($expected, $this->subject()->isEnabled());
    }

    public function testTheNonceIsMagentosOwn(): void
    {
        $nonces = $this->createMock(CspNonceProvider::class);
        $nonces->expects(self::once())->method('generateNonce')->willReturn(self::NONCE);
        $this->nonceProvider = $nonces;

        self::assertSame(self::NONCE, $this->subject()->getCspNonce());
    }

    public function testANonceThatCannotBeMadeIsEmptyNotAnException(): void
    {
        $this->nonceProvider->method('generateNonce')->willThrowException(new LocalizedException(__('no CSPRNG')));

        self::assertSame('', $this->subject()->getCspNonce());
    }
}
