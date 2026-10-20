<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

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
        $root = dirname(__DIR__);
        $found = [];
        $checked = 0;
        foreach (['src', 'public', 'templates'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!preg_match('/\.(php|twig)$/', $file->getFilename())) {
                    continue;
                }
                $source = (string)file_get_contents($file->getPathname());
                // xl(), xlt(), xla(), xlj() with a single- or double-quoted literal.
                preg_match_all("/\\bxl[tajx]?\\(\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/", $source, $m);
                foreach ($m[1] as $literal) {
                    $checked++;
                    $text = stripcslashes(substr($literal, 1, -1));
                    if (str_contains($text, "'") || str_contains($text, '"')) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $text;
                    }
                }
            }
        }
        self::assertGreaterThan(10, $checked, 'the scan found the translated strings');
        self::assertSame([], $found);
    }
}
