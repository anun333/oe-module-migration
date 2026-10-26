<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Migration\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testAnOlderLibraryWithoutPageLangGivesEnglishLeftToRight(): void
    {
        self::assertSame('en', Page::fromLibrary('pageLang', 'en', PageTestOldLibrary::class));
        self::assertSame('ltr', Page::fromLibrary('pageDir', 'ltr', PageTestOldLibrary::class));
    }

    public function testALibraryWithPageLangIsAsked(): void
    {
        self::assertSame('es', Page::fromLibrary('pageLang', 'en', PageTestNewLibrary::class));
        self::assertSame('rtl', Page::fromLibrary('pageDir', 'ltr', PageTestNewLibrary::class));
    }

    public function testEveryPageDeclaresTheUsersLanguageThroughTheGuard(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            $source = (string)file_get_contents($page);
            self::assertStringNotContainsString('OpenEmr::pageLang()', $source, basename($page));
            if (preg_match('/<html[\s>]/', $source) === 1) {
                self::assertStringContainsString('<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">', $source, basename($page));
            }
        }
    }
}

final class PageTestOldLibrary
{
}

final class PageTestNewLibrary
{
    public static function pageLang(): string
    {
        return 'es';
    }

    public static function pageDir(): string
    {
        return 'rtl';
    }
}
