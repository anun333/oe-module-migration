<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use PHPUnit\Framework\TestCase;

/**
 * On an OpenEMR older than 8.1.0 the bootstrap keeps the module switched off, so its classes aren't
 * loadable there: every page requires floor-guard.php right after globals.php, which answers with a
 * short page instead of an empty HTTP 500 (found on 7.0.2, 2026-10-26).
 */
final class FloorGuardTest extends TestCase
{
    private const REQUIRE = "require_once __DIR__ . '/../floor-guard.php';";

    public function testEveryPageRequiresTheGuardRightAfterGlobals(): void
    {
        $pages = glob(dirname(__DIR__) . '/public/*.php') ?: [];
        self::assertNotEmpty($pages);
        foreach ($pages as $page) {
            $lines = array_values(array_filter(
                array_map('trim', file($page) ?: []),
                static fn(string $line): bool => $line !== '' && !str_starts_with($line, '//')
            ));
            $at = array_search("require_once __DIR__ . '/../../../../globals.php';", $lines, true);
            self::assertIsInt($at, basename($page) . ' requires globals.php');
            self::assertSame(self::REQUIRE, $lines[$at + 1] ?? '', basename($page) . ' requires the guard next');
        }
    }

    public function testTheGuardUsesNothingFromTheModuleOrTheLibrary(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/floor-guard.php');
        self::assertStringNotContainsString('OpenHealthcare\\', $source);
        self::assertStringNotContainsString('OpenEMR\\Modules\\', $source);
        self::assertDoesNotMatchRegularExpression('/^use\s/m', $source);
    }

    public function testBelow81ItAnswersWithAShortPageNamingBothVersions(): void
    {
        $out = $this->runGuard(false);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $out);
        self::assertStringContainsString('This module needs OpenEMR 8.1.0 or later', $out);
        self::assertStringContainsString('OpenEMR version on this site: 7.0.2', $out);
        self::assertStringNotContainsString('after the guard', $out);
    }

    public function testFrom81ItLetsThePageRun(): void
    {
        self::assertSame('after the guard', $this->runGuard(true));
    }

    private function runGuard(bool $has81): string
    {
        $dir = sys_get_temp_dir() . '/floor-guard-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/version.php', "<?php\n\$v_major = '7';\n\$v_minor = '0';\n\$v_patch = '2';\n");
        $bag = $has81 ? "namespace OpenEMR\\Core { class OEGlobalsBag { public function getKernel() {} } }\n" : '';
        $script = "<?php\n" . $bag . "namespace {\n"
            . "function xlt(\$s) { return htmlspecialchars(\$s); }\n"
            . "function text(\$s) { return htmlspecialchars((string)\$s); }\n"
            . "function attr(\$s) { return htmlspecialchars((string)\$s, ENT_QUOTES); }\n"
            . "\$GLOBALS['fileroot'] = " . var_export($dir, true) . ";\n"
            . "require " . var_export(dirname(__DIR__) . '/floor-guard.php', true) . ";\n"
            . "echo 'after the guard';\n}\n";
        file_put_contents($dir . '/run.php', $script);
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/run.php') . ' 2>&1');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
        return $out;
    }
}
