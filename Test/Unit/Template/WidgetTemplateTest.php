<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Template;

use BlueFairy\SlackChatHyva\ViewModel\WidgetViewModel;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;

/**
 * The widget template under Hyvä's strict-CSP Alpine build.
 *
 * That build's evaluator (alpine3-csp.js generateEvaluator) splits a directive
 * value on "." and walks the scope; anything else - an operator, a call with
 * arguments, an assignment, a negation - throws in the browser and the directive
 * does nothing. Nothing on the server notices. So every directive value is
 * checked here to be a bare dot path whose root the component actually defines.
 */
class WidgetTemplateTest extends TestCase
{
    private const DOT_PATH = '/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/';

    /**
     * @return string
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param bool $enabled
     * @param array<string, mixed> $boot
     * @return string
     */
    private function render(bool $enabled, array $boot): string
    {
        $viewModel = $this->createStub(WidgetViewModel::class);
        $viewModel->method('isEnabled')->willReturn($enabled);
        $viewModel->method('getBootConfig')->willReturn($boot);

        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'view_model' ? $viewModel : null
        );
        $block->method('getChildHtml')->willReturn('<script>CHILD</script>');

        $escaper = $this->createStub(Escaper::class);
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);

        $run = static function (string $__file, Template $block, Escaper $escaper): void {
            include $__file;
        };
        ob_start();
        try {
            $run(self::root() . '/view/frontend/templates/widget.phtml', $block, $escaper);
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function boot(): array
    {
        $i18n = [];
        foreach (['title', 'open', 'close', 'intro', 'nameLabel', 'emailLabel', 'messageLabel', 'placeholder',
            'send', 'attach', 'retry', 'endChat'] as $key) {
            $i18n[$key] = 'L:' . $key;
        }
        $i18n['title'] = 'Chat <with> "us" & co';

        return ['restRoot' => 'https://shop.example/rest/default/V1/bluefairy-slack-chat/', 'storeCode' => 'default', 'i18n' => $i18n];
    }

    /**
     * @return array<int, array{0: string, 1: string}> [attribute name, value] for every Alpine directive
     */
    private static function directives(): array
    {
        $html = (string) file_get_contents(self::root() . '/view/frontend/templates/widget.phtml');
        // The markup only: the header comment talks ABOUT directives ("no x-model").
        $html = substr($html, (int) strpos($html, '?>') + 2);
        // And not the PHP echoes inside it (`/* @noEscape */` looks like a directive).
        $html = (string) preg_replace('/<\?(?:=|php)[\s\S]*?\?>/', 'PHP', $html);
        preg_match_all('/\s((?:x-[\w.-]+|@[\w.-]+|:[\w.-]+))(?:="([^"]*)")?/', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $hit): array => [$hit[1], $hit[2] ?? ''], $m);
    }

    public function testADisabledWidgetRendersNothing(): void
    {
        self::assertSame('', trim($this->render(false, self::boot())));
        self::assertStringContainsString('x-data="bfslcChat"', $this->render(true, self::boot()), 'CONTROL: enabled renders');
    }

    public function testTheBootConfigRoundTripsThroughTheDataAttributeEscaped(): void
    {
        $html = $this->render(true, self::boot());

        self::assertSame(1, preg_match('/data-bfslc-config="([^"]*)"/', $html, $m), 'no data-bfslc-config attribute');
        self::assertSame(self::boot(), json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true));
        // Labels are printed escaped, never raw.
        self::assertStringContainsString('Chat &lt;with&gt; &quot;us&quot; &amp; co', $html);
        self::assertStringNotContainsString('Chat <with>', $html);
        // The child script is inside the component root (before its closing tag).
        self::assertMatchesRegularExpression('#<script>CHILD</script>\s*</div>\s*$#', $html);
    }

    public function testEveryDirectiveIsABarePathTheComponentDefines(): void
    {
        $component = (string) file_get_contents(self::root() . '/view/frontend/templates/chat-js.phtml');
        $directives = self::directives();
        self::assertGreaterThanOrEqual(30, count($directives), 'CONTROL: the directive scan found too few directives');

        $checked = 0;
        foreach ($directives as [$name, $value]) {
            if (in_array($name, ['x-cloak', 'x-ref'], true) || str_starts_with($name, 'x-ref')) {
                continue;
            }
            if ($name === 'x-data') {
                self::assertSame('bfslcChat', $value);
                continue;
            }
            if ($name === 'x-for') {
                self::assertSame('message in messages', $value);
                continue;
            }
            self::assertMatchesRegularExpression(self::DOT_PATH, $value, "{$name}=\"{$value}\" is an expression, not a path");
            $rootName = explode('.', $value)[0];
            if ($rootName === 'message') {
                $field = explode('.', $value)[1];
                self::assertMatchesRegularExpression(
                    '/\bmsg\.' . preg_quote($field, '/') . ' = |\b' . preg_quote($field, '/') . ': /',
                    $component,
                    "message.{$field} is read by the template but never set on a message (undefined throws in the CSP build)"
                );
            } else {
                self::assertMatchesRegularExpression(
                    '/^\s{12}(get )?' . preg_quote($rootName, '/') . '(\(|:)/m',
                    $component,
                    "{$name}=\"{$value}\": the component defines no property, getter or method {$rootName}"
                );
            }
            $checked++;
        }
        self::assertGreaterThanOrEqual(30, $checked);
    }

    public function testTheCheckRejectsTheShapesTheCspBuildCannotEvaluate(): void
    {
        foreach (['!isOpen', 'isOpen && hasSession', 'retry(message)', 'draft = ""', "{ 'a': isOpen }"] as $bad) {
            self::assertDoesNotMatchRegularExpression(self::DOT_PATH, $bad, "the path check accepts {$bad}");
        }
        self::assertMatchesRegularExpression(self::DOT_PATH, 'message.bubbleClass');
    }

    public function testNoDirectiveInsertsHtmlOrAssigns(): void
    {
        $names = array_column(self::directives(), 0);
        self::assertContains('x-text', $names, 'CONTROL: x-text is used');
        self::assertNotContains('x-html', $names, 'text must never be inserted as HTML');
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'x-model'))));
    }
}
