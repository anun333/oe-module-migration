<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Common\TranslatedStrings;
use PHPUnit\Framework\TestCase;

/**
 * OpenEMR's xl() turns an apostrophe or a quote mark in a translated string into a backtick
 * (library/translation.inc.php, unless a site turns that off), so "claim's" would show as
 * "claim`s". Every string the module translates is written without them.
 */
final class TranslatedStringsTest extends TestCase
{
    public function testNoTranslatedStringHasAnApostropheOrQuoteMark(): void
    {
        $scan = TranslatedStrings::scan(dirname(__DIR__));
        self::assertGreaterThan(10, $scan['checked'], 'the scan found the translated strings');
        self::assertSame([], $scan['found']);
    }
}
