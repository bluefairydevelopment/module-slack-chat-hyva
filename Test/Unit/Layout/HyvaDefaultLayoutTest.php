<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Layout;

use BlueFairy\SlackChatHyva\ViewModel\WidgetViewModel;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * M2MAP-249. This package's layout must reach Hyvä store views ONLY. Hyvä mirrors
 * every page handle with a hyva_ prefix when, and only when, the theme is a Hyvä
 * theme (Hyva\Theme\Observer\AddLayoutHandles); a plain default.xml would apply on
 * Luma too, serving an Alpine widget to a store with no Alpine and removing the
 * working Luma one. module-packagist-provisioner-hyva shipped six such files and
 * served dead Alpine templates to Luma shoppers until a second theme was walked.
 */
class HyvaDefaultLayoutTest extends TestCase
{
    private const LUMA_BLOCK = 'bluefairy.slc.widget.luma';
    private const HYVA_BLOCK = 'bluefairy.slc.widget.hyva';
    private const HYVA_JS_BLOCK = 'bluefairy.slc.widget.hyva.js';

    /**
     * @return string
     */
    private static function layoutDir(): string
    {
        return dirname(__DIR__, 3) . '/view/frontend/layout';
    }

    /**
     * @return SimpleXMLElement
     */
    private static function hyvaDefault(): SimpleXMLElement
    {
        $xml = simplexml_load_file(self::layoutDir() . '/hyva_default.xml');
        self::assertNotFalse($xml, 'view/frontend/layout/hyva_default.xml is missing or does not parse');

        return $xml;
    }

    public function testEveryLayoutFileThisPackageShipsIsHyvaPrefixed(): void
    {
        $files = array_map('basename', glob(self::layoutDir() . '/*.xml') ?: []);

        self::assertContains('hyva_default.xml', $files, 'CONTROL: the layout directory was not read');
        self::assertFileDoesNotExist(self::layoutDir() . '/default.xml', 'a default.xml applies on Luma store views too');
        self::assertSame(
            [],
            array_values(array_filter($files, static fn (string $f): bool => !str_starts_with($f, 'hyva_'))),
            'a layout file without the hyva_ prefix applies on every theme, Luma included'
        );
    }

    public function testItAddsTheHyvaWidgetToBeforeBodyEndWithTheViewModel(): void
    {
        $xml = self::hyvaDefault();

        $blocks = $xml->xpath('//referenceContainer[@name="before.body.end"]/block[@name="' . self::HYVA_BLOCK . '"]') ?: [];
        self::assertCount(1, $blocks, 'the Hyvä widget block is not added to before.body.end exactly once');
        self::assertSame('BlueFairy_SlackChatHyva::widget.phtml', (string) $blocks[0]['template']);

        $arguments = $blocks[0]->xpath('arguments/argument[@name="view_model"]') ?: [];
        self::assertCount(1, $arguments);
        self::assertSame(WidgetViewModel::class, trim((string) $arguments[0]));

        // The script is a child, rendered once inside the component root.
        $children = $blocks[0]->xpath('block[@name="' . self::HYVA_JS_BLOCK . '"]') ?: [];
        self::assertCount(1, $children, 'the component script is not a child of the widget block');
        self::assertSame('BlueFairy_SlackChatHyva::chat-js.phtml', (string) $children[0]['template']);
        self::assertSame(WidgetViewModel::class, trim((string) ($children[0]->xpath('arguments/argument[@name="view_model"]')[0] ?? '')));

        // And the widget template asks for that child by the same name.
        self::assertStringContainsString(
            "getChildHtml('" . self::HYVA_JS_BLOCK . "')",
            (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/widget.phtml')
        );
    }

    public function testItRemovesTheLumaWidget(): void
    {
        $removals = [];
        foreach (self::hyvaDefault()->xpath('//referenceBlock[@remove="true"]') ?: [] as $node) {
            $removals[] = (string) $node['name'];
        }

        self::assertSame([self::LUMA_BLOCK], $removals, 'hyva_default.xml must remove the Luma widget, and only it');
        // Control: the hyva block is in the same file and is NOT removed.
        self::assertNotContains(self::HYVA_BLOCK, $removals);
    }

    /**
     * M2MAP-292: a removal of a name nothing declares is a no-op that reads like a
     * decision. The Luma block is declared by the base package; when its checkout
     * sits beside this one, check it really declares that name.
     *
     * SKIPS until the base package has any frontend layout at all - at the time of
     * writing (2026-10-03) its Luma widget was being built in parallel. Once
     * module-slack-chat/view/frontend/layout exists, the skip can no longer happen
     * and a missing declaration goes red.
     */
    public function testTheBasePackageDeclaresTheBlockThisRemoves(): void
    {
        $base = getenv('SLACKCHAT_BASE_SRC') ?: dirname(__DIR__, 4) . '/module-slack-chat';
        $layouts = glob($base . '/view/frontend/layout/*.xml') ?: [];
        if (!is_dir($base . '/view/frontend/layout')) {
            self::markTestSkipped('no base-package frontend layout at ' . $base . ' to check the Luma block name against');
        }

        $declared = [];
        foreach ($layouts as $file) {
            preg_match_all('/<block\b[^>]*\bname="([^"]+)"/', (string) file_get_contents($file), $m);
            $declared = array_merge($declared, $m[1]);
        }
        self::assertNotEmpty($declared, 'CONTROL: no block declarations were read from the base package layout');
        self::assertContains(
            self::LUMA_BLOCK,
            $declared,
            'the base package declares no block named ' . self::LUMA_BLOCK . ', so the removal here does nothing '
            . 'and a Hyvä store would render both widgets'
        );
    }
}
