<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * Hyvä's Tailwind build scans the THEME. A module's utility classes reach the
 * compiled stylesheet only if the module's own `view/frontend/tailwind/module.css`
 * declares an `@source` glob over its templates (Tailwind 4 themes), or its
 * `tailwind.config.js` lists them as content (Tailwind 3 themes).
 *
 * Ported from module-jira-hyva, which shipped that file with **no `@source` at
 * all** - 1,466 bytes of comment, including a comment explaining that the file is
 * what brings the classes in (M2MAP-474). Measured on hyvatest against the SERVED
 * stylesheet: 39 of 110 distinct class tokens had no rule, with no console error
 * and no 404, on a page every other gate passed.
 *
 * Two defects in the ORIGINAL test are not ported (M2MAP-474, cured first in
 * module-cart-products-hyva 9877e02):
 *
 *  - its comment stripper recognised only a `/**` opener, so a directive
 *    commented out with a plain `/* ... *\/` still counted as declared - that
 *    test was 5 of 5 green with its package's only directive commented out;
 *  - it modelled the glob with PHP's glob(), which reads `**` as a single `*`.
 *    Tailwind's `**` matches any depth INCLUDING ZERO, and this package's two
 *    templates sit directly in templates/ - glob() would have reported both
 *    uncovered. The glob is modelled here by globToRegex(), with controls.
 *
 * Nothing else in this package's gates can see this. phpcs and phpstan do not
 * read CSS; the storefront returns 200 with the right markup either way. So every
 * assertion below is written against a specimen this test plants itself.
 */
class TailwindScansThisPackagesTemplatesTest extends TestCase
{
    private const SOURCE_FILE = '/view/frontend/tailwind/module.css';

    /** The templates this package ships today; the exact count, not a floor. */
    private const SHIPPED_TEMPLATES = ['chat-js.phtml', 'widget.phtml'];

    /**
     * @return string
     */
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return string
     */
    private static function moduleCss(): string
    {
        $path = self::packageRoot() . self::SOURCE_FILE;
        self::assertFileExists($path, 'the Tailwind registration file is missing entirely');
        $css = file_get_contents($path);
        self::assertIsString($css, 'the Tailwind registration file is unreadable');

        return $css;
    }

    /**
     * Strip block comments, so a directive mentioned only in prose does not count.
     *
     * The opener is `/*` or `/**` FOLLOWED BY WHITESPACE. The obvious `!/\*.*?\*\/!s`
     * EATS THE GLOB: `../templates/**\/*.phtml` contains `/*` ... `*\/`, so a general
     * strip rewrites it to `../templates*.phtml` (testTheStripperDoesNotEatAGlobstarPath
     * is the control). A `/**`-only opener is too narrow the other way (see the class
     * comment). The `/**` inside a globstar path is followed by `/`, not whitespace,
     * so it survives.
     *
     * @param string $css
     * @return string
     */
    private static function directives(string $css): string
    {
        return (string) preg_replace('!/\*\*?(?=\s)[\s\S]*?\*/!', '', $css);
    }

    /**
     * Tailwind/fast-glob semantics for the subset these globs use: `**\/` is any
     * number of directories including none, `*` is any run of characters within
     * one path segment, `?` one character within a segment.
     *
     * @param string $glob A glob relative to some base, e.g. "../templates/**\/*.phtml"
     * @return string A PCRE matching a normalised path relative to the same base
     */
    private static function globToRegex(string $glob): string
    {
        $out = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $c = $glob[$i];
            if ($c === '*' && ($glob[$i + 1] ?? '') === '*') {
                if (($glob[$i + 2] ?? '') === '/') {
                    $out .= '(?:[^/]+/)*';
                    $i += 2;
                } else {
                    $out .= '.*';
                    $i += 1;
                }
                continue;
            }
            if ($c === '*') {
                $out .= '[^/]*';
                continue;
            }
            if ($c === '?') {
                $out .= '[^/]';
                continue;
            }
            $out .= preg_quote($c, '#');
        }

        return '#^' . $out . '$#';
    }

    /**
     * Resolve "../" and "./" in a slash path without touching the filesystem.
     *
     * @param string $path
     * @return string
     */
    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /**
     * Does a glob written relative to view/frontend/tailwind/ reach this package path?
     *
     * @param string $glob
     * @param string $packageRelativePath e.g. view/frontend/templates/widget.phtml
     * @return bool
     */
    private static function globReaches(string $glob, string $packageRelativePath): bool
    {
        $anchored = self::normalise('view/frontend/tailwind/' . $glob);
        // Normalising "**" segments is safe: they are never "." or "..".
        return preg_match(self::globToRegex($anchored), $packageRelativePath) === 1;
    }

    /**
     * Every .phtml under view/frontend/templates at ANY depth, package-relative.
     *
     * @return string[]
     */
    private static function shippedTemplates(): array
    {
        $root = self::packageRoot();
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/view/frontend/templates', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'phtml') {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return string[]
     */
    private static function sourceGlobs(): array
    {
        preg_match_all('/@source\s+"([^"]+)"/', self::directives(self::moduleCss()), $m);

        return $m[1];
    }

    public function testTheRegistrationFileDeclaresAtLeastOneSourceDirective(): void
    {
        self::assertMatchesRegularExpression(
            '/@source\b/',
            self::directives(self::moduleCss()),
            'view/frontend/tailwind/module.css declares no @source outside its comments, so '
            . 'Hyvä imports the file and scans none of this package\'s templates'
        );
    }

    public function testTheSourceGlobReachesEveryTemplateThisPackageShips(): void
    {
        $globs = self::sourceGlobs();
        self::assertSame(['../templates/**/*.phtml'], $globs, 'the @source globs changed - re-read this test');

        $templates = self::shippedTemplates();
        // The exact set, so an empty or shrunken search space cannot pass and a
        // new template has to be added here on purpose.
        self::assertSame(
            array_map(static fn (string $f): string => 'view/frontend/templates/' . $f, self::SHIPPED_TEMPLATES),
            $templates,
            'the templates found are not the ones this package ships - the search space is wrong '
            . 'or a template was added without updating SHIPPED_TEMPLATES'
        );

        foreach ($templates as $template) {
            $covered = false;
            foreach ($globs as $glob) {
                $covered = $covered || self::globReaches($glob, $template);
            }
            self::assertTrue(
                $covered,
                sprintf(
                    '%s is not reached by any @source glob (%s), so every utility used only there '
                    . 'is absent from the compiled stylesheet',
                    $template,
                    implode(', ', $globs)
                )
            );
        }
    }

    /**
     * Tailwind 3 themes read tailwind.config.js, not module.css. Same glob, same check.
     */
    public function testTheTailwind3ConfigListsTheSameTemplates(): void
    {
        $js = (string) file_get_contents(self::packageRoot() . '/view/frontend/tailwind/tailwind.config.js');
        $code = (string) preg_replace('!/\*\*?(?=\s)[\s\S]*?\*/!', '', $js);
        self::assertSame(
            1,
            preg_match('/content:\s*\[\s*\'([^\']+)\'/', $code, $m),
            'tailwind.config.js declares no content path outside its comments'
        );
        foreach (self::shippedTemplates() as $template) {
            self::assertTrue(self::globReaches($m[1], $template), $template . ' is not in tailwind.config.js content');
        }
    }

    /**
     * The control. A glob is a claim about a search space, and a claim about a
     * search space is worth nothing until it has been seen to exclude something.
     */
    public function testTheGlobCheckRejectsAFileOutsideIt(): void
    {
        $outside = 'view/frontend/layout/hyva_default.xml';
        self::assertFileExists(self::packageRoot() . '/' . $outside, 'the specimen this control needs does not exist');

        foreach (self::sourceGlobs() as $glob) {
            self::assertFalse(
                self::globReaches($glob, $outside),
                'the @source glob matches a layout XML file, so it is broader than intended and '
                . 'this test cannot distinguish a covered template from an uncovered one'
            );
        }
        self::assertTrue(
            self::globReaches('../templates/**/*.phtml', 'view/frontend/templates/widget.phtml'),
            'CONTROL: the matcher does not reach a real template, so its "false" above means nothing'
        );
    }

    /**
     * The matcher's own controls: `**` at depth zero and depth two, and `*` never
     * crossing a slash - the three ways PHP's glob() and Tailwind disagree.
     */
    public function testTheGlobMatcherModelsTailwindGlobstar(): void
    {
        $glob = '../templates/**/*.phtml';
        self::assertTrue(self::globReaches($glob, 'view/frontend/templates/a.phtml'), '** must match zero directories');
        self::assertTrue(self::globReaches($glob, 'view/frontend/templates/x/y/a.phtml'), '** must match any depth');
        self::assertFalse(self::globReaches($glob, 'view/frontend/templates/a.phtml.bak'), 'the extension must anchor');
        self::assertFalse(self::globReaches('../templates/*.phtml', 'view/frontend/templates/x/a.phtml'), '* must not cross /');
        self::assertFalse(self::globReaches($glob, 'view/frontend/layout/a.phtml'), 'the base directory must anchor');

        // And PHP's glob() really does differ, so a port that reverts to it is caught
        // by this comment's reason rather than by luck.
        $phpGlob = glob(self::packageRoot() . '/view/frontend/templates/**/*.phtml') ?: [];
        self::assertSame(
            [],
            $phpGlob,
            'PHP glob() now reaches this package\'s top-level templates through **, so the reason '
            . 'globToRegex() exists has changed - re-read this test'
        );
    }

    /**
     * The comment-stripping control: prove the stripper removes a planted
     * `@source` inside each comment form, so the first test cannot pass on prose.
     */
    public function testACommentedOutSourceDoesNotCountAsADeclaration(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/@source\b/',
            self::directives("/*\n * See the @source directive.\n */\n"),
            'the comment stripper leaves @source mentions inside a /* comment'
        );

        // The shape that costs a real directive, not prose: one @source line wrapped
        // in a plain /* */. A /**-only stripper leaves it in place.
        self::assertDoesNotMatchRegularExpression(
            '/@source\b/',
            self::directives('/* @source "../templates/**/*.phtml"; */' . "\n"),
            'a @source line commented out with /* */ still counts as a declaration'
        );

        $specimen = "/**\n * See the @source directive.\n */\n";
        self::assertDoesNotMatchRegularExpression(
            '/@source\b/',
            self::directives($specimen),
            'the comment stripper leaves @source mentions inside a doc comment, which is exactly '
            . 'how the original defect read as cured'
        );

        $specimen .= '@source "../templates/**/*.phtml";' . "\n";
        self::assertMatchesRegularExpression(
            '/@source\b/',
            self::directives($specimen),
            'the comment stripper removed a real directive'
        );
    }

    /**
     * The control for the control. A globstar path contains `/*` and `*\/`, so a
     * naive block-comment strip silently truncates it - and the truncated glob
     * still matches SOMETHING, which is why the damage reads as a finding about
     * the package rather than about the stripper.
     */
    public function testTheStripperDoesNotEatAGlobstarPath(): void
    {
        $specimen = '@source "../templates/**/*.phtml";';

        self::assertStringContainsString(
            '../templates/**/*.phtml',
            self::directives($specimen),
            'the comment stripper mangled a globstar path'
        );

        self::assertSame(
            '@source "../templates*.phtml";',
            (string) preg_replace('!/\*.*?\*/!s', '', $specimen),
            'the naive stripper no longer truncates the glob, so this control has '
            . 'stopped demonstrating anything and should be re-aimed or removed'
        );
    }
}
