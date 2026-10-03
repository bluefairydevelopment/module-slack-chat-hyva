<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\SlackChatHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * This package is sold. Ported from module-jira-hyva, whose LICENSE shipped the
 * Open Software License v3.0 - an OSI open source licence granting a royalty-free,
 * sublicensable right to redistribute - while composer.json declared
 * "proprietary". Estate measurement 2026-09-11, 35 BlueFairy packages: 10 declared
 * proprietary and shipped OSL-3.0, 3 shipped the GPL, 12 shipped no licence file;
 * not one test opened a licence file.
 *
 * ADAPTED, NOT COPIED. The Slack Chat packages ship the short proprietary notice
 * (that measurement counted them among the 8 correct ones), not the house EULA, so
 * jira-hyva's EULA-marker and "Version X.Y.Z" assertions do not apply here. What
 * is asserted is that the declaration and the shipped text agree and that the text
 * grants nothing. Nothing here is a legal opinion.
 */
class TheLicenceAMerchantReceivesTest extends TestCase
{
    /** Grant language from the licences actually found in this estate. */
    private const OPEN_SOURCE_GRANTS = [
        'Open Software License',
        'GNU GENERAL PUBLIC LICENSE',
        'Academic Free License',
        'royalty-free, non-exclusive, sublicensable license',
    ];

    private const NOTICE_MARKERS = [
        'Copyright © 2026 Blue Fairy Development. All rights reserved.',
        'This software is proprietary.',
        'without the prior',
    ];

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
    private static function licencePath(): string
    {
        $found = glob(self::packageRoot() . '/LICEN[CS]E*') ?: [];
        self::assertCount(
            1,
            $found,
            'expected exactly one licence file in the package root, found: '
            . (implode(', ', array_map('basename', $found)) ?: 'none')
        );

        return $found[0];
    }

    public function testTheComposerDeclarationAndTheShippedTextAgree(): void
    {
        $composer = json_decode((string) file_get_contents(self::packageRoot() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(['proprietary'], (array) ($composer['license'] ?? []));

        $text = (string) file_get_contents(self::licencePath());
        foreach (self::OPEN_SOURCE_GRANTS as $grant) {
            self::assertStringNotContainsStringIgnoringCase(
                $grant,
                $text,
                sprintf('the shipped licence contains "%s" while composer.json says proprietary', $grant)
            );
        }
    }

    public function testTheShippedTextIsTheProprietaryNotice(): void
    {
        $text = (string) file_get_contents(self::licencePath());
        foreach (self::NOTICE_MARKERS as $marker) {
            self::assertStringContainsString($marker, $text, sprintf('the licence does not carry "%s"', $marker));
        }
    }

    /**
     * The controls: each predicate is run against a specimen chosen to make it fire,
     * because an assertion of absence over prose is the shape most likely to be vacuous.
     */
    public function testEachPredicateFiresOnAPlantedSpecimen(): void
    {
        $osl = 'Open Software License ("OSL") v. 3.0' . "\n"
            . 'Licensor grants You a worldwide, royalty-free, non-exclusive, sublicensable license';

        $hits = 0;
        foreach (self::OPEN_SOURCE_GRANTS as $grant) {
            if (stripos($osl, $grant) !== false) {
                $hits++;
            }
        }
        self::assertSame(2, $hits, 'the open-source predicates do not match an OSL-3.0 header');
        self::assertNotFalse(stripos('GNU GENERAL PUBLIC LICENSE Version 3', self::OPEN_SOURCE_GRANTS[1]));

        foreach (self::NOTICE_MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $osl, sprintf('"%s" appears in OSL-3.0 text, so it does not discriminate', $marker));
        }
    }
}
