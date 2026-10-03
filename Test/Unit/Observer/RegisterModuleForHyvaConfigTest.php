<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Observer;

use BlueFairy\SlackChatHyva\Observer\RegisterModuleForHyvaConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * The observer that makes Hyvä's Tailwind build scan this module.
 *
 * Ported from module-jira-hyva, where the observer had no test in either package
 * until one was written: M2MAP-359 measured what its absence costs on a running
 * store - 43 of 110 distinct classes emitting no CSS - and the cure was checked by
 * asserting the EVENT SUBSCRIPTION existed. That proves the observer is wired,
 * not that it does anything. Both halves are needed: a subscription to a method
 * that returns early is indistinguishable from no subscription at all.
 */
class RegisterModuleForHyvaConfigTest extends TestCase
{
    private const ROOT = '/var/www/magento';

    private ComponentRegistrar $registrar;

    protected function setUp(): void
    {
        // Magento defines BP at bootstrap; Test/bootstrap.php deliberately does not
        // load app/bootstrap.php, so define it here if it is absent. It is a
        // constant, so this is once per process and every expectation below is
        // derived from BP rather than from the literal.
        if (!defined('BP')) {
            define('BP', self::ROOT);
        }

        $this->registrar = $this->createStub(ComponentRegistrar::class);
    }

    private function subject(): RegisterModuleForHyvaConfig
    {
        return new RegisterModuleForHyvaConfig($this->registrar);
    }

    private function eventCarrying(?DataObject $config): Observer
    {
        $observer = $this->createStub(Observer::class);
        $observer->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'config' ? $config : null
        );

        return $observer;
    }

    public function testItAppendsThisModulesPathRelativeToTheMagentoRoot(): void
    {
        $modulePath = constant('BP') . '/vendor/bluefairy/module-slack-chat-hyva';
        $this->registrar->method('getPath')->willReturnCallback(
            static fn (string $type, string $name): ?string
                => $type === ComponentRegistrar::MODULE && $name === 'BlueFairy_SlackChatHyva' ? $modulePath : null
        );

        $config = new DataObject();
        $this->subject()->execute($this->eventCarrying($config));

        self::assertSame(
            [['src' => 'vendor/bluefairy/module-slack-chat-hyva']],
            $config->getData('extensions'),
            'the path Hyva receives must be ROOT-RELATIVE. An absolute one is written into '
            . "the theme's generated source list and resolves on the build machine and "
            . 'nowhere else.'
        );
    }

    /**
     * It REGISTERS THIS PACKAGE, not the base one. The class was copied from
     * BlueFairy_JiraHyva, and a MODULE_NAME left pointing at that package (or at
     * BlueFairy_SlackChat, whose templates are Luma) would ask Hyva to scan a
     * directory carrying none of this widget's Tailwind classes - and would not
     * scan the directory that does.
     */
    public function testItRegistersThisPackageAndNotTheBaseModule(): void
    {
        $this->registrar = $this->createMock(ComponentRegistrar::class);
        $this->registrar->expects($this->once())
            ->method('getPath')
            ->with(ComponentRegistrar::MODULE, 'BlueFairy_SlackChatHyva')
            ->willReturn(constant('BP') . '/app/code/BlueFairy/SlackChatHyva');

        $this->subject()->execute($this->eventCarrying(new DataObject()));
    }

    public function testItPreservesExtensionsAlreadyRegisteredByOtherModules(): void
    {
        $this->registrar->method('getPath')->willReturn(constant('BP') . '/app/code/BlueFairy/SlackChatHyva');

        $config = new DataObject(['extensions' => [['src' => 'app/code/Other/Module']]]);
        $this->subject()->execute($this->eventCarrying($config));

        self::assertSame(
            [
                ['src' => 'app/code/Other/Module'],
                ['src' => 'app/code/BlueFairy/SlackChatHyva'],
            ],
            $config->getData('extensions'),
            'appending must not drop what another module registered - Hyva calls every '
            . 'observer on the same config object'
        );
    }

    public function testItDoesNothingWhenTheEventCarriesNoConfig(): void
    {
        $this->registrar = $this->createMock(ComponentRegistrar::class);
        $this->registrar->expects($this->never())->method('getPath');

        $this->subject()->execute($this->eventCarrying(null));
    }

    /**
     * An unregistered module returns null from getPath(). Appending anyway would put
     * `['src' => '']` into the list, and an empty source path is not "nothing" - it
     * is the THEME ROOT, so the build would scan everything.
     */
    public function testItDoesNothingWhenTheModulePathIsUnknown(): void
    {
        $this->registrar->method('getPath')->willReturn(null);

        $config = new DataObject();
        $this->subject()->execute($this->eventCarrying($config));

        self::assertNull($config->getData('extensions'));
    }

    /**
     * The other half: the subscription. Read from the XML rather than assumed, and
     * with a control that the XPath can see an observer at all.
     */
    public function testItIsSubscribedToHyvasConfigGenerateEventOnTheFrontend(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/events.xml');
        self::assertNotFalse($xml, 'etc/frontend/events.xml does not parse');

        $all = $xml->xpath('//event/observer') ?: [];
        self::assertCount(1, $all, 'CONTROL: expected exactly one observer in etc/frontend/events.xml');

        $wired = $xml->xpath('//event[@name="hyva_config_generate_before"]/observer') ?: [];
        self::assertCount(1, $wired, 'the observer is not subscribed to hyva_config_generate_before');
        self::assertSame(RegisterModuleForHyvaConfig::class, (string) $wired[0]['instance']);
    }
}
