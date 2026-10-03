<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * M2MAP-597 / M2MAP-598. A tag is a release, and a release must not be cut over a
 * red suite. This package's gate is .githooks/pre-push; this test holds the facts
 * that decide whether that gate exists: the hook is TRACKED, it RUNS THE GATES on a
 * tag push, THIS CLONE is wired to it, and it cannot reach a merchant (in a
 * single-package repository the root IS the package, so that depends on
 * `.githooks/ export-ignore`, asserted on the real archive).
 *
 * The branch and tag rules are RUN in throwaway repositories, not read.
 *
 * Ported from module-jira-hyva, adapted to THIS hook: it runs phpstan from
 * MAGENTO_ROOT's vendor/bin (not a copied phar), and it has no CHANGELOG-heading
 * check on tags, so those two jira-hyva assertions are not ported. If the hook
 * gains either, port them.
 */
class TheReleaseGateIsArmedTest extends TestCase
{
    /** @return string Test/Unit/Package -> the repository root, three levels up. */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param string $args
     * @return string
     */
    private static function git(string $args): string
    {
        $out = [];
        $status = 0;
        // Reviewed and kept. This test's subject IS the git repository and it runs
        // standalone; nothing outside this class reaches the command, and Test/ is
        // export-ignored, so this never leaves the development tree.
        // phpcs:ignore Magento2.Security.InsecureFunction
        exec('cd ' . escapeshellarg(self::root()) . ' && git ' . $args . ' 2>&1', $out, $status);
        if ($status !== 0) {
            throw new RuntimeException("git {$args} exited {$status}: " . implode("\n", $out));
        }
        return implode("\n", $out);
    }

    /**
     * @return string
     */
    private static function hookPath(): string
    {
        return self::root() . '/.githooks/pre-push';
    }

    public function testTheHookIsTrackedAndExecutable(): void
    {
        self::assertSame(
            '.githooks/pre-push',
            trim(self::git('ls-files -- .githooks/pre-push')),
            '.githooks/pre-push is not tracked, so every fresh clone has no release gate'
        );
        self::assertTrue(is_executable(self::hookPath()), 'the hook is not executable, so git will not run it');
    }

    /** Over the EXECUTABLE lines: the header names every gate, so a whole-file match cannot fail. */
    public function testTheHookRunsEveryGateOnATagPush(): void
    {
        $code = self::executableLines((string) file_get_contents(self::hookPath()));
        self::assertStringContainsString('refs/tags/', $code);
        foreach (['phpunit', 'phpcs', 'phpstan', 'composer validate'] as $gate) {
            self::assertMatchesRegularExpression(
                '/^\s*gate \S+ .*' . preg_quote($gate, '/') . '/m',
                $code,
                "the hook no longer runs {$gate} through gate() on a tag push"
            );
        }
        self::assertStringContainsString('MAGENTO_ROOT', $code);
    }

    public function testACommentNamingAGateDoesNotCountAsRunningIt(): void
    {
        $code = self::executableLines("#!/usr/bin/env bash\n# runs phpunit, phpcs, phpstan, composer validate\nexit 0\n");
        self::assertStringNotContainsString('phpunit', $code, 'CONTROL: the comment stripper left a gate name');
        self::assertMatchesRegularExpression(
            '/^\s*gate \S+ .*phpunit/m',
            self::executableLines("    gate phpunit env A=b \$MAGENTO_ROOT/vendor/bin/phpunit -c phpunit.xml.dist\n"),
            'CONTROL: a real gate line was not recognised'
        );
    }

    /**
     * NOTHING BUT THE MODULE MAY REACH A MERCHANT. With a file that must ship and
     * directories that must not, so the listing is shown able to see both.
     */
    public function testThePublishedArchiveShipsNoDevelopmentFiles(): void
    {
        $entries = array_values(array_filter(
            array_map('trim', explode("\n", self::git('archive HEAD | tar -t'))),
            static fn (string $e): bool => $e !== ''
        ));
        self::assertContains('registration.php', $entries, 'CONTROL: the archive is not the shipped file set');

        foreach (['Test/' => 'Test/', 'githooks' => '.githooks/', '.claude' => '.claude/'] as $needle => $what) {
            self::assertSame(
                [],
                array_values(array_filter($entries, static fn (string $e): bool => str_contains($e, $needle))),
                "the published archive contains {$what} - it is not export-ignored in .gitattributes"
            );
        }
    }

    public function testTheBranchRuleSeesEveryCommitOfAFirstPush(): void
    {
        self::assertSame(0, self::runBranchRule([
            ['src/a.php', 'one'],
            ['CHANGELOG.md', 'an entry'],
            ['src/b.php', 'two'],
        ]), 'a three-commit first push that DOES update CHANGELOG.md was rejected');
        self::assertSame(0, self::runBranchRule([['CHANGELOG.md', 'x']]), 'CONTROL: a single changelog commit was rejected');
        self::assertSame(1, self::runBranchRule([['src/a.php', 'one'], ['src/b.php', 'two']]), 'CONTROL: no changelog was accepted');
        self::assertSame(
            1,
            self::runBranchRule([['src/a.php', 'one'], ['docs/CHANGELOG.md', 'x']]),
            'a CHANGELOG.md other than the root one satisfied the rule'
        );
    }

    /**
     * Each refusal checked by its MESSAGE, in the hook's order; the last case gets
     * past both and stops at MAGENTO_ROOT, proving the earlier refusals are not the
     * hook refusing everything.
     */
    public function testATagIsRefusedUnlessItIsHeadOnACleanTree(): void
    {
        [$status, $err] = self::runTagRule('first', false);
        self::assertSame(1, $status);
        self::assertStringContainsString('points at', $err);

        [$status, $err] = self::runTagRule('head', true);
        self::assertSame(1, $status);
        self::assertStringContainsString('working tree has uncommitted', $err);

        [$status, $err] = self::runTagRule('head', false);
        self::assertSame(1, $status);
        self::assertStringContainsString('MAGENTO_ROOT', $err, 'CONTROL: a clean HEAD tag did not reach the gates');
        self::assertStringNotContainsString('points at', $err);
        self::assertStringNotContainsString('uncommitted', $err);
    }

    public function testThisCloneIsWiredToTheHook(): void
    {
        $out = [];
        // phpcs:ignore Magento2.Security.InsecureFunction
        exec('cd ' . escapeshellarg(self::root()) . ' && git config core.hooksPath 2>/dev/null', $out);
        self::assertSame(
            '.githooks',
            trim(implode('', $out)),
            "This clone's core.hooksPath is not .githooks, so the tracked release gate is NOT "
            . "running and a tag push here is ungated. Fix, once:\n\n    git config core.hooksPath .githooks\n"
        );
    }

    /**
     * @param string $shell
     * @return string
     */
    private static function executableLines(string $shell): string
    {
        $kept = [];
        foreach (explode("\n", $shell) as $line) {
            $stripped = preg_replace('/^\s*#.*$/', '', $line);
            if (trim((string) $stripped) !== '') {
                $kept[] = $stripped;
            }
        }
        return implode("\n", $kept);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $files
     * @return int
     */
    private static function runBranchRule(array $files): int
    {
        $dir = sys_get_temp_dir() . '/slackchathyva-branch-rule-' . bin2hex(random_bytes(6));
        $script = self::repoPreamble($dir);
        foreach ($files as [$path, $body]) {
            $script[] = 'mkdir -p "$(dirname ' . escapeshellarg($path) . ')"';
            $script[] = 'printf %s ' . escapeshellarg($body) . ' >> ' . escapeshellarg($path);
            $script[] = 'git add ' . escapeshellarg($path) . ' && git commit -q -m c';
        }
        $script[] = 'sha=$(git rev-parse HEAD)';
        $script[] = 'set +e';
        $script[] = 'printf "refs/heads/main $sha refs/heads/main 0000000000000000000000000000000000000000\n" | bash '
            . escapeshellarg(self::hookPath()) . ' >/dev/null 2>&1';
        return self::runScript($script, $dir)[0];
    }

    /**
     * @param string $which 'first' (a tag on a commit that is not HEAD) or 'head'
     * @param bool $dirty
     * @return array{0: int, 1: string}
     */
    private static function runTagRule(string $which, bool $dirty): array
    {
        $dir = sys_get_temp_dir() . '/slackchathyva-tag-rule-' . bin2hex(random_bytes(6));
        $script = self::repoPreamble($dir);
        $script[] = 'printf one > a.txt && git add a.txt && git commit -q -m one';
        $script[] = 'first=$(git rev-parse HEAD)';
        $script[] = 'printf ' . escapeshellarg("# Changelog\n\n## [9.9.9] - today\n") . ' > CHANGELOG.md';
        $script[] = 'git add -A && git commit -q -m two';
        $script[] = 'sha=$' . ($which === 'first' ? '(git rev-parse $first)' : '(git rev-parse HEAD)');
        $script[] = $dirty ? 'printf x > untracked.txt' : 'true';
        $script[] = 'set +e';
        $script[] = 'printf "refs/tags/v9.9.9 $sha refs/tags/v9.9.9 0000000000000000000000000000000000000000\n" '
            . '| env -u MAGENTO_ROOT bash ' . escapeshellarg(self::hookPath())
            . ' >/dev/null 2>' . escapeshellarg($dir . '.err');
        return self::runScript($script, $dir, $dir . '.err');
    }

    /**
     * @param string $dir
     * @return list<string>
     */
    private static function repoPreamble(string $dir): array
    {
        return [
            'set -e',
            'mkdir -p ' . escapeshellarg($dir),
            'cd ' . escapeshellarg($dir),
            'git init -q .',
            'git config user.email gate@example.test',
            'git config user.name gate',
        ];
    }

    /**
     * @param list<string> $script
     * @param string $dir
     * @param string|null $errFile
     * @return array{0: int, 1: string}
     */
    private static function runScript(array $script, string $dir, ?string $errFile = null): array
    {
        $script[] = 'status=$?';
        $script[] = 'cd /';
        $script[] = 'rm -rf ' . escapeshellarg($dir);
        $script[] = 'exit $status';

        $out = [];
        $status = 0;
        // Reviewed and kept: this asserts what a shell script does, which only running it establishes.
        // phpcs:ignore Magento2.Security.InsecureFunction
        exec('bash -c ' . escapeshellarg(implode("\n", $script)), $out, $status);

        $stderr = '';
        if ($errFile !== null && is_file($errFile)) {
            $stderr = (string) file_get_contents($errFile);
            unlink($errFile);
        }
        return [$status, $stderr];
    }
}
