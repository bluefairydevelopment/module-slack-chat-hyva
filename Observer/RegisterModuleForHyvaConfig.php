<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Tell Hyvä's `hyva-config` generator where this module's Tailwind sources live.
 *
 * Ported from BlueFairy_JiraHyva, where M2MAP-359 measured what its absence
 * costs: 43 of 110 distinct classes in that module's templates emitted no CSS
 * on Hyvä/default, confirmed live (a button with padding-top 0px, gap: normal),
 * with no console error and no 404.
 *
 * Here the cost would be worse than spacing: the chat panel is `fixed` and its
 * open/closed state is partly carried by utility classes. A purged `fixed` or
 * `z-50` puts the panel in the document flow at the bottom of every page.
 *
 * Registration is necessary and NOT sufficient: the theme's CSS must be rebuilt
 * afterwards (README, "Rebuild the theme CSS"), and the @source glob in
 * view/frontend/tailwind/module.css is what actually puts the templates into
 * Tailwind's content scan (M2MAP-474).
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    private const MODULE_NAME = 'BlueFairy_SlackChatHyva';

    /**
     * @param ComponentRegistrar $componentRegistrar
     */
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar
    ) {
    }

    /**
     * Append this module's root-relative path to the Hyvä extension list.
     *
     * @param Observer $event
     * @return void
     */
    public function execute(Observer $event): void
    {
        $config = $event->getData('config');
        if ($config === null) {
            return;
        }

        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if ($path === null) {
            return;
        }

        // BP is Magento's root-path constant, defined at bootstrap; without it a
        // relative path cannot be formed, and a wrong one is worse than none.
        if (!defined('BP')) {
            return;
        }

        $root = (string) constant('BP');
        $extensions = $config->hasData('extensions') ? (array) $config->getData('extensions') : [];
        $extensions[] = ['src' => substr($path, strlen($root) + 1)];
        $config->setData('extensions', $extensions);
    }
}
