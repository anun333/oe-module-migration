<?php

/**
 * What mapping needs from OpenEMR's own lists and users. The OpenEMR implementation reads
 * them from the database; tests use a fixed set.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Map;

interface Lookups
{
    /** The race list's option id for a CDC race code (OpenEMR keeps it in the option's notes), or null. */
    public function race(string $cdcCode): ?string;

    /** The ethnicity list's option id for a CDC ethnicity code, or null. */
    public function ethnicity(string $cdcCode): ?string;

    /** The language list's option id for an ISO 639-1 code ("en"), or null. */
    public function language(string $iso): ?string;

    /** The reaction list's option id for a SNOMED CT code, or null. */
    public function reaction(string $snomed): ?string;

    /** The OpenEMR user with this NPI, or null. */
    public function userByNpi(string $npi): ?int;

    /** The visit category (pc_catid) imported encounters are given. */
    public function visitCategory(): int;

    /** The facility imported encounters are given. */
    public function facility(): int;
}
