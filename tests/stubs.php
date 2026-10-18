<?php

/**
 * A stand-in for OpenEMR's global translation helper, which the isolated tests
 * cannot load. Dev only: loaded through autoload-dev, never installed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (!function_exists('xl')) {
    function xl(string $constant): string
    {
        return $constant;
    }
}
