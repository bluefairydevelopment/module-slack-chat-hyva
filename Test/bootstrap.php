<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Unit-test and static-analysis bootstrap for module-slack-chat-hyva.
 *
 * Adapted from the base package's bootstrap (module-slack-chat/Test/bootstrap.php),
 * itself from module-jira's, the house reference. The lessons it carries were paid
 * for in sibling packages:
 *
 * 1. LOAD vendor/autoload.php, NEVER app/bootstrap.php. Magento's bootstrap
 *    unregisters the phar stream wrapper, and phpstan runs from a phar: it dies in
 *    its own error renderer, exit 255, zero findings.
 *
 * 2. PREPEND the source tree, so a run reads THIS checkout, never the copy
 *    composer installed into the Magento root's vendor/.
 *
 * 3. THE BASE PACKAGE FROM ITS SIBLING CHECKOUT. This package's view model
 *    depends on BlueFairy\SlackChat\Model\Config. The three Slack Chat packages are
 *    built together, in three repositories side by side under ~/Sites/Modules, and
 *    the base may not be installed on MAGENTO_ROOT yet (or may be installed at an
 *    older release). So BlueFairy\SlackChat\ is read from ../module-slack-chat when
 *    that checkout exists (or from SLACKCHAT_BASE_SRC), ahead of composer. Which
 *    one was used is printed on every run, so a green says what it was measured
 *    against.
 *
 * 4. REFUSE to run without compiled DI. Magento generates extension-attribute
 *    interfaces at di:compile time; with generated/code empty the synthesiser
 *    below would stand in empty ones and the run would measure phantoms.
 *
 * 5. Synthesise a Factory/Extension class ONLY when composer cannot find a real
 *    file for it - never by name pattern alone.
 *
 * 6. M2MAP-682: under phpstan, refuse when ANY Factory/Extension the shipped code
 *    imports is unresolved - Magento's own as well as ours. A sparse generated/code
 *    once let a Magento factory be synthesised with create() returning null;
 *    phpstan stopped checking everything downstream and exited 0. The unit suite
 *    may still synthesise: it mocks them on purpose.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '';

if ($magentoRoot === '' || !is_file($magentoRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "MAGENTO_ROOT is not set to a Magento installation.\n"
        . "These tests mock Magento classes, so the framework has to be loadable:\n"
        . "  MAGENTO_ROOT=/path/to/magento /path/to/magento/vendor/bin/phpunit -c phpunit.xml.dist\n"
    );
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $composerLoader */
$composerLoader = require $magentoRoot . '/vendor/autoload.php';

$src = dirname(__DIR__);

/**
 * A PSR-4 loader for one prefix rooted at one directory, prepended.
 *
 * @param string $prefix
 * @param string $root
 * @return void
 */
function slackChatHyvaBootstrapPrepend(string $prefix, string $root): void
{
    spl_autoload_register(static function (string $class) use ($prefix, $root): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
}

slackChatHyvaBootstrapPrepend('BlueFairy\\SlackChatHyva\\', $src);

$baseSrc = getenv('SLACKCHAT_BASE_SRC') ?: dirname($src) . '/module-slack-chat';
$baseFrom = 'composer (MAGENTO_ROOT vendor)';
if (is_file($baseSrc . '/registration.php') && is_file($baseSrc . '/Model/Config.php')) {
    slackChatHyvaBootstrapPrepend('BlueFairy\\SlackChat\\', $baseSrc);
    $baseFrom = $baseSrc;
}

if (!class_exists(\BlueFairy\SlackChat\Model\Config::class)) {
    fwrite(STDERR, sprintf(
        "\nREFUSING to run: BlueFairy\\SlackChat\\Model\\Config cannot be loaded.\n"
        . "Neither a base-package checkout at %s nor bluefairy/module-slack-chat in %s/vendor\n"
        . "provides it. Set SLACKCHAT_BASE_SRC to a module-slack-chat checkout.\n\n",
        $baseSrc,
        $magentoRoot
    ));
    exit(1);
}

$generated = $magentoRoot . '/generated/code/';

// A proxy: proves DI was compiled at some point on this root, not that it was
// compiled with this module (which declares no extension attributes of its own,
// so there is no stronger sentinel to look for).
foreach (['Magento/Catalog/Api/Data/ProductExtensionInterface.php'] as $sentinel) {
    if (!is_file($generated . $sentinel)) {
        fwrite(STDERR, sprintf(
            "\nREFUSING to run: %s is missing.\n"
            . "The Magento install at %s has no compiled DI at all.\n"
            . "Run:  cd %s && php -d memory_limit=-1 bin/magento setup:di:compile\n\n",
            $generated . $sentinel,
            $magentoRoot,
            $magentoRoot
        ));
        exit(1);
    }
}

$underAnalyser = str_contains(strtolower((string) ($_SERVER['argv'][0] ?? '')), 'phpstan')
    || class_exists('PHPStan\Analyser\Analyser', false);

$imports = [];
$unresolved = [];
foreach (['Observer', 'ViewModel'] as $dir) {
    if (!is_dir($src . '/' . $dir)) {
        continue;
    }
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if (!$entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }
        preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]+(?:Factory|ExtensionInterface|Extension))(?:\s+as\s+\w+)?\s*;/m',
            (string) file_get_contents($entry->getPathname()),
            $matches
        );
        foreach ($matches[1] as $name) {
            $imports[$name] = true;
            if ($composerLoader->findFile($name) === false && !is_file($generated . str_replace('\\', '/', $name) . '.php')) {
                $unresolved[$name] = true;
            }
        }
    }
}

// The denominator and the base source, on every run, so a green carries both.
fwrite(STDERR, sprintf(
    "slackchathyva-bootstrap: base package from %s; %d Factory/Extension import(s) in shipped code, %d unresolved%s\n",
    $baseFrom,
    count($imports),
    count($unresolved),
    $underAnalyser ? ' [static analysis]' : ''
));

if ($unresolved !== [] && $underAnalyser) {
    fwrite(STDERR, sprintf(
        "\nREFUSING to analyse: %d imported class(es) cannot be resolved and would be SYNTHESISED as\n"
        . "empty stand-ins whose create() returns null - phpstan would stop checking everything they\n"
        . "return and exit 0 over its own blind spot (M2MAP-682):\n  %s\n"
        . "Generate them on %s first:\n  php -d memory_limit=-1 bin/magento setup:di:compile\n\n",
        count($unresolved),
        implode("\n  ", array_keys($unresolved)),
        $magentoRoot
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($generated): void {
    $file = $generated . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Should the bootstrap invent a stand-in for this class? Never for a class
 * composer (or generated/code, registered above) can supply.
 *
 * @param string $class
 * @param \Composer\Autoload\ClassLoader $loader
 * @return bool
 */
function slackChatHyvaBootstrapShouldSynthesise(string $class, \Composer\Autoload\ClassLoader $loader): bool
{
    $isFactory = str_ends_with($class, 'Factory');
    $isExtension = str_ends_with($class, 'ExtensionInterface');
    $isExtData = str_ends_with($class, 'Extension') && !$isExtension;

    if (!$isFactory && !$isExtension && !$isExtData) {
        return false;
    }

    return $loader->findFile($class) === false;
}

spl_autoload_register(static function (string $class) use ($composerLoader): void {
    if (class_exists($class, false) || interface_exists($class, false)) {
        return;
    }
    if (!slackChatHyvaBootstrapShouldSynthesise($class, $composerLoader)) {
        return;
    }

    $isExtension = str_ends_with($class, 'ExtensionInterface');
    $isExtData = str_ends_with($class, 'Extension') && !$isExtension;
    $pos = strrpos($class, '\\');
    $namespace = $pos === false ? '' : substr($class, 0, $pos);
    $shortName = $pos === false ? $class : substr($class, $pos + 1);
    $nsDecl = $namespace === '' ? '' : sprintf('namespace %s;', $namespace);

    if ($isExtension) {
        eval(sprintf('%s interface %s {}', $nsDecl, $shortName));
        return;
    }
    if ($isExtData) {
        $iface = $class . 'Interface';
        $implements = (interface_exists($iface) || class_exists($iface)) ? ' implements \\' . $iface : '';
        eval(sprintf('%s class %s%s {}', $nsDecl, $shortName, $implements));
        return;
    }
    eval(sprintf('%s class %s { public function create(array $data = []) { return null; } }', $nsDecl, $shortName));
});
