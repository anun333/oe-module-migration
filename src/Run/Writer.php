<?php

/**
 * Where mapped records go. The OpenEMR writer calls OpenEMR's own services; the tests use
 * a fake.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Run;

use OpenHealthcare\Migration\Map\Mapped;

interface Writer
{
    /** A patient already in OpenEMR with the same first and last name, birth date and sex, by pid, or null. */
    public function findPatient(string $fname, string $lname, string $dob, string $sex): ?int;

    /**
     * Writes one record and returns its id in OpenEMR (pid for a patient, the encounter number for an encounter).
     *
     * @param int|null $pid       the patient's pid, for everything but a patient
     * @param int|null $encounter the encounter number, for vitals (and records tied to a visit)
     * @throws \RuntimeException when OpenEMR refuses it; the message says why
     */
    public function write(Mapped $record, ?int $pid, ?int $encounter): int;
}
