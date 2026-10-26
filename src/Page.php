<?php

/**
 * The language and writing direction each page declares on its html element: the signed-in user's
 * OpenEMR language, through openhealthcare-common 1.4.0. On a site that copies modules in by hand,
 * the first module's copy of the library serves every module, and an older copy has no pageLang();
 * the pages then say English, as they did before, rather than stop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration;

use OpenHealthcare\Common\OpenEmr;

final class Page
{
    /** A BCP 47 tag such as en, es or zh-Hant. */
    public static function lang(): string
    {
        return self::fromLibrary('pageLang', 'en');
    }

    /** ltr or rtl. */
    public static function dir(): string
    {
        return self::fromLibrary('pageDir', 'ltr');
    }

    /**
     * The library's answer, or the fallback when the copy that loaded has no such method.
     *
     * @param class-string $library
     */
    public static function fromLibrary(string $method, string $fallback, string $library = OpenEmr::class): string
    {
        return method_exists($library, $method) ? (string)$library::$method() : $fallback;
    }
}
