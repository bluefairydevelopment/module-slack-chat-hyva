<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * The three Slack Chat packages live in THREE SEPARATE GIT REPOSITORIES and ship as
 * a set at one version. Ported from module-jira-hyva, where the same layout let the
 * companion require the base at `^1.2` while the set was being cut as 1.1.0 - a
 * companion composer would simply refuse to resolve.
 *
 * The invariant, checkable without the sibling repositories: this package's
 * constraint on the base must admit THIS package's own release version, read from
 * the top of its CHANGELOG.
 */
class VersionConstraintsAdmitThisReleaseTest extends TestCase
{
    /**
     * @return string
     */
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The newest version heading: "## 1.0.0" or "## [1.0.0]".
     *
     * @return string
     */
    private static function releaseVersion(): string
    {
        $changelog = file_get_contents(self::packageRoot() . '/CHANGELOG.md');
        self::assertIsString($changelog, 'CHANGELOG.md is unreadable - this test would have nothing to check');

        if (!preg_match('/^##\s+\[?(\d+\.\d+\.\d+)\]?/m', $changelog, $m)) {
            self::fail('No version heading found in CHANGELOG.md, so this test would check nothing.');
        }

        return $m[1];
    }

    public function testTheChangelogStatesAVersion(): void
    {
        self::assertSame('1.0.0', self::releaseVersion(), 'the release this package is being built as moved - update this line deliberately');
    }

    public function testTheConstraintOnTheBasePackageAdmitsThisRelease(): void
    {
        $composer = json_decode((string) file_get_contents(self::packageRoot() . '/composer.json'), true);
        self::assertIsArray($composer, 'composer.json did not parse');

        $constraint = $composer['require']['bluefairy/module-slack-chat'] ?? null;
        self::assertIsString($constraint, 'This package does not require bluefairy/module-slack-chat, whose REST API it calls.');

        $release = self::releaseVersion();
        self::assertTrue(
            self::caretAdmits($constraint, $release),
            sprintf('Released as %s but requires the base at "%s", which does not admit it.', $release, $constraint)
        );
    }

    /**
     * Prove the comparison rejects a wrong constraint, so a pass above is not the
     * comparison being broken.
     */
    public function testTheConstraintCheckRejectsAConstraintThatIsWrong(): void
    {
        self::assertFalse(self::caretAdmits('^1.2', '1.1.0'), '^1.2 must NOT admit 1.1.0');
        self::assertTrue(self::caretAdmits('^1.0', '1.0.0'), '^1.0 must admit 1.0.0');
        self::assertTrue(self::caretAdmits('^1.1', '1.9.3'), '^1.1 must admit later minors');
        self::assertFalse(self::caretAdmits('^1.1', '2.0.0'), '^1.1 must not admit the next major');
    }

    /**
     * Minimal caret semantics: ^X.Y.Z admits >=X.Y.Z and <(X+1).0.0.
     *
     * @param string $constraint
     * @param string $version
     * @return bool
     */
    private static function caretAdmits(string $constraint, string $version): bool
    {
        if (!preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', trim($constraint), $c)) {
            self::fail("constraint \"{$constraint}\" is not the simple caret form this test understands");
        }

        [$major, $minor, $patch] = [(int) $c[1], (int) $c[2], (int) ($c[3] ?? 0)];
        [$vMajor, $vMinor, $vPatch] = array_map('intval', explode('.', $version));

        if ($vMajor !== $major) {
            return false;
        }

        return [$vMinor, $vPatch] >= [$minor, $patch];
    }
}
