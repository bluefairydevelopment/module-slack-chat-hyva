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
     * The base package's checkout to read layouts from: SLACKCHAT_BASE_SRC when set
     * (as Test/bootstrap.php honours it), else the sibling checkout these packages
     * are built in, else the copy composer installed into MAGENTO_ROOT's vendor/.
     * The first candidate that IS the base package (has registration.php) wins,
     * whether or not it has layouts - a base package without the Luma block must
     * fail, not fall through to an older copy that has it.
     *
     * @param array<int, string> $candidates
     * @return string|null
     */
    public static function resolveBasePackage(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate . '/registration.php')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function baseCandidates(): array
    {
        $magentoRoot = (string) getenv('MAGENTO_ROOT');

        return [
            (string) getenv('SLACKCHAT_BASE_SRC'),
            dirname(__DIR__, 4) . '/module-slack-chat',
            $magentoRoot === '' ? '' : $magentoRoot . '/vendor/bluefairy/module-slack-chat',
        ];
    }

    /**
     * M2MAP-292: a removal of a name nothing declares is a no-op that reads like a
     * decision. The Luma block is declared by the base package; check it really
     * declares that name.
     *
     * This used to SKIP when no base checkout sat beside this one, which meant it
     * never ran anywhere but this workstation. It now also reads the base package
     * composer installed under MAGENTO_ROOT, and when there is no base package at
     * all it FAILS - unless BFSLC_ALLOW_STANDALONE=1 says the run is deliberately
     * without one, in which case it skips and says so.
     */
    public function testTheBasePackageDeclaresTheBlockThisRemoves(): void
    {
        $candidates = self::baseCandidates();
        $base = self::resolveBasePackage($candidates);
        if ($base === null) {
            if (getenv('BFSLC_ALLOW_STANDALONE') === '1') {
                self::markTestSkipped('BFSLC_ALLOW_STANDALONE=1 and no base package at: ' . implode(', ', array_filter($candidates)));
            }
            self::fail(
                'bluefairy/module-slack-chat was not found at any of: ' . implode(', ', array_filter($candidates))
                . '. Install it on MAGENTO_ROOT, set SLACKCHAT_BASE_SRC, or set BFSLC_ALLOW_STANDALONE=1 to run without it.'
            );
        }

        $layouts = glob($base . '/view/frontend/layout/*.xml') ?: [];
        $declared = [];
        foreach ($layouts as $file) {
            preg_match_all('/<block\b[^>]*\bname="([^"]+)"/', (string) file_get_contents($file), $m);
            $declared = array_merge($declared, $m[1]);
        }
        self::assertNotSame([], $layouts, 'the base package at ' . $base . ' ships no frontend layout, so it declares no Luma widget');
        self::assertNotEmpty($declared, 'CONTROL: no block declarations were read from ' . $base . '/view/frontend/layout');
        self::assertContains(
            self::LUMA_BLOCK,
            $declared,
            'the base package at ' . $base . ' declares no block named ' . self::LUMA_BLOCK . ', so the removal here '
            . 'does nothing and a Hyvä store would render both widgets'
        );
    }

    /**
     * The resolver, against directories this test plants: the first real base
     * package wins, a directory without registration.php is not one, and none
     * at all is null (which the test above turns into a failure).
     */
    public function testTheBasePackageIsResolvedInOrderAndAbsenceIsNull(): void
    {
        $root = sys_get_temp_dir() . '/bfslc-base-' . bin2hex(random_bytes(4));
        $empty = $root . '/empty';
        $vendor = $root . '/vendor/bluefairy/module-slack-chat';
        $sibling = $root . '/module-slack-chat';
        foreach ([$empty, $vendor, $sibling] as $dir) {
            mkdir($dir, 0700, true);
        }
        try {
            self::assertNull(self::resolveBasePackage(['', $empty, $root . '/missing']), 'a directory without registration.php was taken for the base package');

            touch($vendor . '/registration.php');
            self::assertSame($vendor, self::resolveBasePackage(['', $empty, $vendor]), 'the vendor copy is not found');

            touch($sibling . '/registration.php');
            self::assertSame($sibling, self::resolveBasePackage([$sibling, $vendor]), 'an earlier candidate does not win');
        } finally {
            foreach ([$vendor, $sibling] as $dir) {
                if (is_file($dir . '/registration.php')) {
                    unlink($dir . '/registration.php');
                }
            }
            foreach ([$sibling, $vendor, $root . '/vendor/bluefairy', $root . '/vendor', $empty, $root] as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }
    }
}
