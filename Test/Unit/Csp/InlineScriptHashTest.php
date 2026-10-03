<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Csp;

use BlueFairy\SlackChatHyva\ViewModel\WidgetViewModel;
use Hyva\Theme\Model\HtmlPageContent;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Template\Html\Minifier;
use PHPUnit\Framework\TestCase;

/**
 * The widget's inline script is allowed under a strict CSP three ways: Magento's
 * nonce on the tag, Hyvä's registerInlineScript() (a per-response hash or nonce),
 * and a static sha256 in etc/csp_whitelist.xml. This test guards the two that can
 * silently stop working:
 *
 *  - the static hash goes stale the moment anyone edits the script, and nothing
 *    reports it - the browser just refuses the script and the bubble never opens;
 *  - registerInlineScript() acts ONLY if the script is the last element in the
 *    output buffer when it is called (HyvaCsp.php:154-155 via
 *    HtmlPageContent::extractLastElement); anything printed in between and it
 *    returns having done nothing.
 *
 * The body is extracted with Hyvä's own HtmlPageContent, which is what computes
 * the hash Hyvä sends, and cross-checked against an independent extraction.
 */
class InlineScriptHashTest extends TestCase
{
    private const NONCE = 'dGVzdC1ub25jZS0xMjM0NTY3ODkw';

    private const TEMPLATE = '/view/frontend/templates/chat-js.phtml';

    /**
     * @return string
     */
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Render a chat-js template file exactly as Magento's PHP engine would: the
     * template sees $block and $escaper (and $hyvaCsp on a Hyvä 1.3.11+ theme).
     *
     * @param string $file
     * @param object|null $hyvaCsp
     * @return string
     */
    private function render(string $file, ?object $hyvaCsp = null): string
    {
        $viewModel = $this->createStub(WidgetViewModel::class);
        $viewModel->method('getCspNonce')->willReturn(self::NONCE);

        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'view_model' ? $viewModel : null
        );

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );

        $dictionary = ['block' => $block, 'escaper' => $escaper];
        if ($hyvaCsp !== null) {
            $dictionary['hyvaCsp'] = $hyvaCsp;
        }

        $run = static function (string $__file, array $__vars): void {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            extract($__vars);
            include $__file;
        };

        ob_start();
        try {
            $run($file, $dictionary);
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    /**
     * The script body as Hyvä extracts it before hashing (HyvaCsp::addInlineScriptHashToCspHeader).
     *
     * @param string $rendered
     * @return string
     */
    private static function hyvaBody(string $rendered): string
    {
        return (new HtmlPageContent())->extractLastElementContent(rtrim($rendered), 'script');
    }

    /**
     * @param string $body
     * @return string
     */
    private static function sha256(string $body): string
    {
        return base64_encode(hash('sha256', $body, true));
    }

    /**
     * @return string
     */
    private static function whitelistedHash(): string
    {
        $xml = simplexml_load_file(self::packageRoot() . '/etc/csp_whitelist.xml');
        self::assertNotFalse($xml, 'etc/csp_whitelist.xml does not parse');
        $values = $xml->xpath('//policy[@id="script-src"]/values/value[@id="bfslc-hyva-chat-js"]') ?: [];
        self::assertCount(1, $values, 'csp_whitelist.xml has no script-src value bfslc-hyva-chat-js');
        self::assertSame('hash', (string) $values[0]['type']);
        self::assertSame('sha256', (string) $values[0]['algorithm']);

        return trim((string) $values[0]);
    }

    public function testTheWhitelistedHashIsTheHashOfTheScriptAsEmitted(): void
    {
        $rendered = $this->render(self::packageRoot() . self::TEMPLATE);
        $body = self::hyvaBody($rendered);

        // Independent extraction: from the end of the opening tag to </script>.
        self::assertSame(1, preg_match('#<script nonce="[^"]*">(.*)</script>\s*$#s', $rendered, $m));
        self::assertSame($m[1], $body, 'Hyvä and this test disagree about where the script body starts and ends');
        self::assertStringContainsString("Alpine.data('bfslcChat', bfslcChat)", $body, 'CONTROL: the extracted body is the component');

        self::assertSame(
            self::sha256($body),
            self::whitelistedHash(),
            'etc/csp_whitelist.xml does not carry the sha256 of chat-js.phtml\'s script. The script was '
            . 'edited without updating the hash; put this value in the file: ' . self::sha256($body)
        );
    }

    /**
     * The control: a one-byte edit to the script must change the answer.
     */
    public function testAnEditedScriptNoLongerMatchesTheWhitelistedHash(): void
    {
        $source = (string) file_get_contents(self::packageRoot() . self::TEMPLATE);
        $edited = str_replace("'use strict';", "'use strict'; ", $source);
        self::assertNotSame($source, $edited, 'CONTROL: the planted edit did not apply');

        $copy = tempnam(sys_get_temp_dir(), 'bfslc-chatjs-') . '.phtml';
        file_put_contents($copy, $edited);
        try {
            $body = self::hyvaBody($this->render($copy));
        } finally {
            unlink($copy);
        }

        self::assertNotSame('', $body, 'CONTROL: the edited copy rendered no script');
        self::assertNotSame(self::whitelistedHash(), self::sha256($body));
    }

    /**
     * The body carries no PHP: two renders with different nonces give the same body.
     */
    public function testTheBodyIsStaticAndOnlyTheTagCarriesTheNonce(): void
    {
        $rendered = $this->render(self::packageRoot() . self::TEMPLATE);

        self::assertStringContainsString('<script nonce="' . self::NONCE . '">', $rendered);
        self::assertStringNotContainsString(self::NONCE, self::hyvaBody($rendered));

        $source = (string) file_get_contents(self::packageRoot() . self::TEMPLATE);
        $open = strpos($source, '<script nonce=');
        self::assertIsInt($open, 'CONTROL: the template has no nonce-carrying script tag');
        $bodyStart = (int) strpos($source, '">', $open) + 2;
        $bodyEnd = (int) strpos($source, '</script>', $bodyStart);
        self::assertStringNotContainsString('<?', substr($source, $bodyStart, $bodyEnd - $bodyStart), 'PHP inside the script body makes its hash vary');
    }

    /**
     * Production mode with dev/template/minify_html on serves a MINIFIED copy of the
     * template. The real Minifier is run over it; the script must hash the same.
     */
    public function testMagentosHtmlMinifierDoesNotChangeTheScriptBody(): void
    {
        $source = (string) file_get_contents(self::packageRoot() . self::TEMPLATE);
        $written = null;

        $read = $this->createStub(ReadInterface::class);
        $read->method('readFile')->willReturn($source);
        $read->method('getRelativePath')->willReturn('chat-js.phtml');
        $readFactory = $this->createStub(ReadFactory::class);
        $readFactory->method('create')->willReturn($read);

        $write = $this->createStub(WriteInterface::class);
        $write->method('isExist')->willReturn(true);
        $write->method('writeFile')->willReturnCallback(
            static function ($path, $content) use (&$written): int {
                $written = (string) $content;
                return strlen((string) $content);
            }
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($write);
        $filesystem->method('getDirectoryRead')->willReturn($read);

        (new Minifier($filesystem, $readFactory))->minify(self::packageRoot() . self::TEMPLATE);
        self::assertIsString($written, 'the minifier wrote nothing');
        self::assertNotSame($source, $written, 'CONTROL: the minifier changed nothing at all, so it was not exercised');

        $copy = tempnam(sys_get_temp_dir(), 'bfslc-chatjs-min-') . '.phtml';
        file_put_contents($copy, $written);
        try {
            $body = self::hyvaBody($this->render($copy));
        } finally {
            unlink($copy);
        }

        self::assertSame(self::whitelistedHash(), self::sha256($body), 'the minified template emits a different script body');
    }

    /**
     * The minifier control: it DOES rewrite a script containing a // comment, so the
     * test above is not passing because the minifier leaves scripts alone.
     */
    public function testTheMinifierWouldHaveChangedALineComment(): void
    {
        $specimen = "<script nonce=\"x\">\nvar a = 1; // note\n</script>\n";
        $written = null;
        $read = $this->createStub(ReadInterface::class);
        $read->method('readFile')->willReturn($specimen);
        $read->method('getRelativePath')->willReturn('s.phtml');
        $readFactory = $this->createStub(ReadFactory::class);
        $readFactory->method('create')->willReturn($read);
        $write = $this->createStub(WriteInterface::class);
        $write->method('isExist')->willReturn(true);
        $write->method('writeFile')->willReturnCallback(
            static function ($path, $content) use (&$written): int {
                $written = (string) $content;
                return 0;
            }
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($write);
        $filesystem->method('getDirectoryRead')->willReturn($read);

        (new Minifier($filesystem, $readFactory))->minify('/tmp/s.phtml');

        self::assertSame("<script nonce=\"x\">\nvar a = 1;</script>", $written);
    }

    /**
     * On a Hyvä 1.3.11+ theme the template calls registerInlineScript() - and at the
     * moment it does, the script must be the LAST element in the buffer, or Hyvä
     * finds nothing and returns silently.
     */
    public function testHyvaRegistersTheScriptWhileItIsTheLastElement(): void
    {
        $seen = null;
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects(self::once())->method('registerInlineScript')->willReturnCallback(
            static function () use (&$seen): void {
                $seen = (string) ob_get_contents();
            }
        );

        $this->render(self::packageRoot() . self::TEMPLATE, $hyvaCsp);

        self::assertIsString($seen);
        $last = (new HtmlPageContent())->extractLastElement(rtrim($seen), 'script');
        self::assertStringStartsWith('<script nonce="' . self::NONCE . '">', $last, 'the script was not the last element when Hyvä looked');
        self::assertSame(self::whitelistedHash(), self::sha256(self::hyvaBody($seen)));

        // Control: something printed after </script> blinds Hyvä's extractor.
        self::assertSame('', (new HtmlPageContent())->extractLastElement(rtrim($seen) . "\n<div></div>", 'script'));
    }

    public function testWithoutHyvaCspTheTemplateStillRenders(): void
    {
        $rendered = $this->render(self::packageRoot() . self::TEMPLATE);

        self::assertStringContainsString("Alpine.data('bfslcChat', bfslcChat)", $rendered);
        self::assertStringEndsWith('</script>', rtrim($rendered));
    }
}
