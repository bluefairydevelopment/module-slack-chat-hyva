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

    /**
     * The file input is sr-only (the visible control is its label), so without a
     * peer focus style a keyboard user tabbing onto it sees nothing at all.
     * `peer-*` only works when the input is an EARLIER SIBLING of the label, so the
     * order is asserted too.
     */
    public function testTheFileInputHasAVisibleKeyboardFocusIndicator(): void
    {
        $html = $this->render(true, self::boot());

        self::assertSame(1, preg_match('#<input id="bfslc-file"[^>]*class="([^"]*)"#', $html, $input), 'CONTROL: the file input is rendered');
        $inputClasses = preg_split('/\s+/', $input[1]) ?: [];
        self::assertContains('sr-only', $inputClasses, 'CONTROL: the input is visually hidden, so its label must show focus');
        self::assertContains('peer', $inputClasses, 'the file input is not a Tailwind peer, so no label style can follow its focus');

        self::assertSame(1, preg_match('#<label for="bfslc-file"[^>]*class="([^"]*)"#', $html, $label));
        $labelClasses = preg_split('/\s+/', $label[1]) ?: [];
        self::assertContains('peer-focus-visible:ring-2', $labelClasses, 'the label shows no ring when the file input has keyboard focus');
        self::assertContains('peer-focus-visible:ring-blue-500', $labelClasses);

        // Sibling order: the input, then the label, with no element boundary between.
        self::assertSame(1, preg_match('#<input id="bfslc-file"[^>]*>\s*<label for="bfslc-file"#', $html), 'the label is not the next sibling of the input');
    }

    /**
     * A message turning to "Not sent" is a TEXT change in an existing node; a
     * log announcing additions only never speaks it. And the unread badge sits
     * inside a button whose aria-label replaces its content, so the count must
     * be in the label itself (and the badge hidden, or it is read twice).
     */
    public function testScreenReadersHearStatusChangesAndTheUnreadCount(): void
    {
        $html = $this->render(true, self::boot());

        self::assertSame(1, preg_match('#<div x-ref="log"[^>]*>#', $html, $log), 'CONTROL: the log is rendered');
        self::assertStringContainsString('aria-live="polite"', $log[0]);
        self::assertStringContainsString('aria-relevant="additions text"', $log[0], 'the log does not announce text changes');

        self::assertSame(1, preg_match('#<button type="button"\s+x-ref="bubble"[^>]*>#', $html, $bubble), 'CONTROL: the bubble is rendered');
        self::assertStringContainsString(':aria-label="bubbleLabel"', $bubble[0], 'the bubble\'s accessible name does not carry the unread count');
        self::assertStringContainsString('aria-label="L:open"', $bubble[0], 'CONTROL: the static label is still there before Alpine starts');

        self::assertSame(1, preg_match('#<span x-show="hasUnread"[^>]*>#', $html, $badge));
        self::assertStringContainsString('aria-hidden="true"', $badge[0], 'the visual badge would be read as a bare number');
        self::assertMatchesRegularExpression('#<span role="status" class="sr-only" x-text="unreadAnnouncement"></span>#', $html);
    }

    /**
     * Round-2 parity with the Luma widget (D6, W10, W11): the availability banner
     * is a status region; the log is aria-busy while the transcript loads; the
     * inputs are 16px (text-base - below that iOS zooms the page on focus) and
     * take what the server takes (name 120, email 255); a Retry that a 429/503
     * Retry-After holds back is disabled, not hidden.
     */
    public function testInputsAndLiveRegionsMatchTheLumaWidget(): void
    {
        $html = $this->render(true, self::boot());

        self::assertSame(1, preg_match('#<p x-show="hasBanner"[^>]*>#', $html, $banner), 'CONTROL: the banner is rendered');
        self::assertStringContainsString('role="status"', $banner[0], 'the availability banner is not announced');

        self::assertSame(1, preg_match('#<div x-ref="log"[^>]*>#', $html, $log));
        self::assertStringContainsString(':aria-busy="logBusy"', $log[0], 'the log is not marked busy while history loads');

        foreach (['bfslc-name' => '120', 'bfslc-email' => '255'] as $id => $max) {
            self::assertSame(1, preg_match('#<input id="' . $id . '"[^>]*>#', $html, $input), "CONTROL: #{$id} is rendered");
            self::assertStringContainsString('maxlength="' . $max . '"', $input[0], "#{$id} does not take what the server takes");
            self::assertContains('text-base', preg_split('/\s+/', (string) preg_replace('#.*class="([^"]*)".*#s', '$1', $input[0])) ?: []);
            self::assertStringNotContainsString('text-sm', $input[0], "#{$id} is under 16px, so iOS zooms on focus");
        }
        self::assertSame(1, preg_match('#<textarea id="bfslc-message"[^>]*>#', $html, $textarea));
        self::assertStringContainsString(' text-base"', $textarea[0]);
        self::assertStringNotContainsString('text-sm', $textarea[0]);

        self::assertSame(1, preg_match('#<button type="button"\s+x-show="message.canRetry"[^>]*>#', $html, $retry), 'CONTROL: the Retry button is rendered');
        self::assertStringContainsString(':disabled="message.retryWaiting"', $retry[0]);
    }

    /**
     * The log scrolls (overflow-y-auto), and a keyboard user can scroll only an
     * element that takes focus - so it is a tab stop, and shows that it has focus.
     */
    public function testTheLogCanBeScrolledFromTheKeyboard(): void
    {
        $html = $this->render(true, self::boot());

        self::assertSame(1, preg_match('#<div x-ref="log"[^>]*>#', $html, $log), 'CONTROL: the log is rendered');
        self::assertSame(1, preg_match('#class="([^"]*)"#', $log[0], $class));
        $classes = preg_split('/\s+/', $class[1]) ?: [];
        self::assertContains('overflow-y-auto', $classes, 'CONTROL: the log is the scrolling region');
        self::assertStringContainsString(' tabindex="0"', $log[0], 'the scrolling log cannot take keyboard focus');
        self::assertContains('focus-visible:ring-2', $classes, 'the log shows no ring when it has keyboard focus');
        self::assertContains('focus-visible:ring-blue-500', $classes);
    }

    public function testNoDirectiveInsertsHtmlOrAssigns(): void
    {
        $names = array_column(self::directives(), 0);
        self::assertContains('x-text', $names, 'CONTROL: x-text is used');
        self::assertNotContains('x-html', $names, 'text must never be inserted as HTML');
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'x-model'))));
    }
}
