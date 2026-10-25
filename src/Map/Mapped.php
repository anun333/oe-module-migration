<?php

/**
 * One source resource as OpenEMR will hold it: what kind of record it becomes, the
 * fields OpenEMR's service takes (the patient and encounter are filled in at import, from
 * the map), and the patient and encounter it belongs to in the source. When it can't be
 * imported, why.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Map;

final class Mapped
{
    public const PATIENT = 'patient';
    public const PROBLEM = 'problem';
    public const ALLERGY = 'allergy';
    public const MEDICATION = 'medication';
    public const IMMUNIZATION = 'immunization';
    public const ENCOUNTER = 'encounter';
    public const VITALS = 'vitals';
    public const DOCUMENT = 'document';
    /** A survey Observation, on OpenEMR's Observation form as an assessment (0.3.0). */
    public const ASSESSMENT = 'assessment';

    /**
     * @param array<string, mixed> $data   the fields for OpenEMR's service
     * @param list<string>         $notes  what was adjusted on the way (a unit converted, a code left out)
     * @param list<string>         $parts  for vitals: the source Observations folded into the one form
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly ?string $patientId,
        public readonly ?string $encounterId,
        public readonly array $data,
        public readonly ?string $skip = null,
        public readonly array $notes = [],
        public readonly array $parts = [],
    ) {
    }

    public static function skipped(string $kind, string $sourceType, string $sourceId, ?string $patientId, string $why): self
    {
        return new self($kind, $sourceType, $sourceId, $patientId, null, [], $why);
    }
}
