<?php

/**
 * Stops a page of this module on an OpenEMR older than 8.1.0 with a short page that says so.
 *
 * Below 8.1.0, openemr.bootstrap.php keeps the module switched off before it registers the module's
 * classes or openhealthcare-common, so a page opened from a bookmark or a typed address would stop
 * on the first class it uses, with an empty HTTP 500. Every page in public/ requires this file right
 * after globals.php. It uses plain PHP and functions every OpenEMR since 7.0 has (xlt, text, attr,
 * sqlQuery), and nothing from this module or openhealthcare-common.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// The same test as openemr.bootstrap.php: 8.1.0 brought OEGlobalsBag::getKernel().
if (class_exists('OpenEMR\\Core\\OEGlobalsBag') && method_exists('OpenEMR\\Core\\OEGlobalsBag', 'getKernel')) {
    return;
}

(static function (): void {
    // The site's version, from core's version.php.
    $version = '';
    $root = is_string($GLOBALS['fileroot'] ?? null) ? $GLOBALS['fileroot'] : dirname(__DIR__, 4);
    if (is_file($root . '/version.php')) {
        $v_major = $v_minor = $v_patch = '';
        include $root . '/version.php';
        $version = $v_major . '.' . $v_minor . '.' . $v_patch;
    }

    // The signed-in user's language as BCP 47, as openhealthcare-common's PageLanguage gives it: core's
    // code where it is one, the right tag where core's two letters mean another language.
    $tags = [
        'se' => ['sv', 'Swedish'], 'el' => ['es-419', 'Spanish (Latin American)'], 'du' => ['nl', 'Dutch'],
        'fq' => ['fr-CA', 'French (Canadian)'], 'zh' => ['zh-Hans', 'Chinese (Simplified)'],
        'zt' => ['zh-Hant', 'Chinese (Traditional)'], 'gr' => ['el', 'Greek'], 'ei' => ['en-IN', 'English (Indian)'],
        'pe' => ['pt-PT', 'Portuguese (European)'], 'pt' => ['pt-BR', 'Portuguese (Brazilian)'],
        'pa' => ['pt-AO', 'Portuguese (Angolan)'], 'po' => ['pl', 'Polish'], 'li' => ['lt', 'Lithuanian'],
        'fp' => ['fil', 'Filipino'], 'au' => ['en-AU', 'English (Australian)'], 'dd' => ['en', 'dummy'],
    ];
    $lang = 'en';
    $dir = 'ltr';
    $choice = $_SESSION['language_choice'] ?? null;
    if (is_numeric($choice) && (int)$choice > 0 && function_exists('sqlQuery')) {
        try {
            $row = sqlQuery('SELECT `lang_code`, `lang_description`, `lang_is_rtl` FROM `lang_languages` WHERE `lang_id` = ?', [(int)$choice]);
        } catch (\Throwable $e) {
            $row = null;
        }
        if (is_array($row) && is_string($row['lang_code'] ?? null)) {
            $code = strtolower(trim($row['lang_code']));
            $known = $tags[$code] ?? null;
            if ($known !== null && strcasecmp(trim((string)($row['lang_description'] ?? '')), $known[1]) === 0) {
                $lang = $known[0];
            } elseif (preg_match('/^[a-z]{2,3}$/', $code) === 1) {
                $lang = $code;
            }
            $dir = (int)($row['lang_is_rtl'] ?? 0) === 1 ? 'rtl' : 'ltr';
        }
    }

    http_response_code(200);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="' . attr($lang) . '" dir="' . attr($dir) . '">' . "\n";
    echo '<head><meta charset="utf-8"><title>' . xlt('Migration') . '</title></head>' . "\n";
    echo '<body><main>' . "\n";
    echo '<h1>' . xlt('Migration') . '</h1>' . "\n";
    echo '<p>' . xlt('This module needs OpenEMR 8.1.0 or later, so it stays switched off on this site.') . '</p>' . "\n";
    if ($version !== '' && $version !== '..') {
        echo '<p>' . xlt('OpenEMR version on this site') . ': ' . text($version) . '</p>' . "\n";
    }
    echo '</main></body>' . "\n" . '</html>' . "\n";
    exit;
})();
