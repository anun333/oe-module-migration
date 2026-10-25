<?php

/**
 * Reads a FHIR export into OpenEMR, or reports what it would do. In dependency order:
 * patients first (matched against the map, then against patients already in OpenEMR),
 * then encounters, then the chart records that need only the patient, then vital signs
 * folded into their encounter's Vitals form. Whatever is already in the map for this
 * source system is left alone, so a rerun adds nothing.
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
use OpenHealthcare\Migration\Map\Mappers;
use OpenHealthcare\Migration\Source\FhirExport;

final class Migrator
{
    public const ALREADY = 'already imported';
    public const DUPLICATE = 'a patient with the same name, birth date and sex is already in OpenEMR';
    public const NO_PATIENT = 'its patient is not imported';
    public const NO_ENCOUNTER = 'its encounter is not imported';
    public const NOT_IN_V1 = 'not imported by this version';
    public const REFERENCED = 'read to fill in the records that point at it, not imported itself';

    /** @var array<string, array{target: string, targetId: int, pid: int}> */
    private array $map;

    /** @var array<string, array{read: int, imported: int, already: int, skipped: array<string, int>, failed: int}> */
    private array $report = [];

    /** @var list<array{type: string, id: string, level: string, message: string}> */
    private array $issues = [];

    /**
     * @param array<string, array{target: string, targetId: int, pid: int}> $map what's already imported, by "Type/id"
     * @param callable(string, string, string, int, int, bool): void|null $record called for each record written:
     *        type, source id, target, target id, pid, linked
     */
    public function __construct(
        private readonly Mappers $mappers,
        private readonly Writer $writer,
        array $map,
        private readonly bool $dryRun,
        private readonly bool $linkDuplicates = false,
        private readonly mixed $record = null,
    ) {
        $this->map = $map;
    }

    /**
     * @return array{report: array<string, array{read: int, imported: int, already: int, skipped: array<string, int>, failed: int}>,
     *               issues: list<array{type: string, id: string, level: string, message: string}>}
     */
    public function run(FhirExport $export): array
    {
        foreach ($export->counts() as $type => $n) {
            $this->report[$type] = ['read' => $n, 'imported' => 0, 'already' => 0, 'skipped' => [], 'failed' => 0];
        }
        foreach ($export->ofType('Patient') as $patient) {
            $this->patient($this->mappers->patient($patient));
        }
        foreach ($export->ofType('Encounter') as $encounter) {
            $this->dependent($this->mappers->encounter($encounter, $export));
        }
        foreach (Mappers::CHART_TYPES as $type) {
            foreach ($export->ofType($type) as $resource) {
                $this->dependent($this->mappers->chart($resource, $export));
            }
        }
        foreach ($this->mappers->vitals($export->ofType('Observation'), $export) as $vitals) {
            $this->dependent($vitals);
        }
        foreach ($this->report as $type => $row) {
            if (in_array($type, Mappers::REFERENCED_TYPES, true)) {
                $this->report[$type]['skipped'][self::REFERENCED] = $row['read'];
            } elseif (isset(Mappers::WAITING_ON_OPENEMR[$type])) {
                $this->report[$type]['skipped'][Mappers::WAITING_ON_OPENEMR[$type]] = $row['read'];
            } elseif (!in_array($type, Mappers::TYPES, true)) {
                $this->report[$type]['skipped'][self::NOT_IN_V1] = $row['read'];
            }
        }
        return ['report' => $this->report, 'issues' => $this->issues];
    }

    private function patient(Mapped $m): void
    {
        $key = 'Patient/' . $m->sourceId;
        if (isset($this->map[$key])) {
            $this->count($m, 'already');
            return;
        }
        if ($m->skip !== null) {
            $this->skip($m, $m->skip);
            return;
        }
        $existing = $this->writer->findPatient((string)($m->data['fname'] ?? ''), (string)($m->data['lname'] ?? ''), (string)($m->data['DOB'] ?? ''), (string)($m->data['sex'] ?? ''));
        if ($existing !== null) {
            if (!$this->linkDuplicates) {
                $this->skip($m, self::DUPLICATE, "possible duplicate of pid $existing");
                return;
            }
            $this->remember($m, Mapped::PATIENT, $existing, $existing, true);
            $this->count($m, 'imported');
            return;
        }
        $this->write($m, null, null);
    }

    /** Everything that needs its patient (and, for vitals, its encounter) imported first. */
    private function dependent(Mapped $m): void
    {
        if ($this->isMapped($m)) {
            $this->count($m, 'already');
            return;
        }
        if ($m->skip !== null) {
            $this->skip($m, $m->skip);
            return;
        }
        $patient = $m->patientId === null ? null : ($this->map['Patient/' . $m->patientId] ?? null);
        if ($patient === null) {
            $this->skip($m, self::NO_PATIENT);
            return;
        }
        $encounter = $m->encounterId === null ? null : ($this->map['Encounter/' . $m->encounterId] ?? null);
        if (in_array($m->kind, [Mapped::VITALS, Mapped::ASSESSMENT], true) && $encounter === null) {
            $this->skip($m, self::NO_ENCOUNTER);
            return;
        }
        $this->write($m, $patient['pid'], $encounter['targetId'] ?? null);
    }

    private function write(Mapped $m, ?int $pid, ?int $encounter): void
    {
        if ($this->dryRun) {
            // Pretend ids keep the dependents' bookkeeping right; nothing is written.
            $id = -1 - count($this->map);
            $this->remember($m, $m->kind, $id, $pid ?? $id, false, false);
            $this->count($m, 'imported');
            return;
        }
        try {
            $id = $this->writer->write($m, $pid, $encounter);
        } catch (\RuntimeException $e) {
            $this->count($m, 'failed');
            $this->issues[] = ['type' => $m->sourceType, 'id' => $m->sourceId, 'level' => 'failed', 'message' => $e->getMessage()];
            return;
        }
        $this->remember($m, $m->kind, $id, $pid ?? $id, false);
        $this->count($m, 'imported');
    }

    /** A record folded from several Observations (a Vitals form) is mapped by each of them, not by itself. */
    private function isMapped(Mapped $m): bool
    {
        if ($m->parts === []) {
            return isset($this->map[$m->sourceType . '/' . $m->sourceId]);
        }
        foreach ($m->parts as $part) {
            if (!isset($this->map['Observation/' . $part])) {
                return false;
            }
        }
        return true;
    }

    private function remember(Mapped $m, string $target, int $targetId, int $pid, bool $linked, bool $persist = true): void
    {
        $keys = $m->parts === [] ? [[$m->sourceType, $m->sourceId]] : array_map(static fn(string $p): array => ['Observation', $p], $m->parts);
        foreach ($keys as [$type, $id]) {
            $this->map["$type/$id"] = ['target' => $target, 'targetId' => $targetId, 'pid' => $pid];
            if ($persist && !$this->dryRun && is_callable($this->record)) {
                ($this->record)($type, $id, $target, $targetId, $pid, $linked);
            }
        }
    }

    private function skip(Mapped $m, string $why, ?string $detail = null): void
    {
        $n = max(1, count($m->parts));
        $this->report[$m->sourceType]['skipped'][$why] = ($this->report[$m->sourceType]['skipped'][$why] ?? 0) + $n;
        $this->issues[] = ['type' => $m->sourceType, 'id' => $m->sourceId, 'level' => 'skipped', 'message' => $detail ?? $why];
    }

    /** Counts the record, and for folded vitals each Observation in it. */
    private function count(Mapped $m, string $what): void
    {
        $n = max(1, count($m->parts));
        $this->report[$m->sourceType] ??= ['read' => 0, 'imported' => 0, 'already' => 0, 'skipped' => [], 'failed' => 0];
        $this->report[$m->sourceType][$what] += $n;
    }
}
