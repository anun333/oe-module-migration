<?php

/**
 * Reads a FHIR export into OpenEMR, or reports what it would do. In dependency order:
 * patients first (matched against the map, then against patients already in OpenEMR),
 * then encounters, then the chart records that need only the patient, then vital signs
 * folded into their encounter's Vitals form. Whatever is already in the map for this
 * source system is left alone, so a rerun adds nothing, and a later export adds only what's
 * new: a vital sign for an encounter already imported goes onto its Vitals form. Each record
 * is written with its map entry in one transaction, so a failure part-way leaves neither.
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
    public const ALREADY_ON_FORM = "a later reading of a vital sign the encounter's Vitals form already has: the form's value kept";
    public const SPLIT_FORM = "its encounter's vital signs are on more than one Vitals form";

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
        $done = array_values(array_filter($m->parts, fn(string $p): bool => isset($this->map['Observation/' . $p])));
        if ($done !== [] && count($done) < count($m->parts)) {
            $this->addToVitals($m, $done);
            return;
        }
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
        $parts = $m->parts === [] ? null : $m->parts;
        try {
            $id = $this->writer->transaction(function () use ($m, $pid, $encounter, $parts): int {
                $id = $this->writer->write($m, $pid, $encounter);
                $this->persist($m, $parts, $m->kind, $id, $pid ?? $id, false);
                return $id;
            });
        } catch (\RuntimeException $e) {
            $this->count($m, 'failed');
            $this->issues[] = ['type' => $m->sourceType, 'id' => $m->sourceId, 'level' => 'failed', 'message' => $e->getMessage()];
            return;
        }
        $this->note($m, $parts, $m->kind, $id, $pid ?? $id);
        $this->count($m, 'imported');
    }

    /**
     * Vitals for an encounter already imported, from a later export with more readings: the new readings go onto
     * the form already there, and a field the form already has keeps its value.
     *
     * @param list<string> $done the folded Observations already mapped
     */
    private function addToVitals(Mapped $m, array $done): void
    {
        $new = array_values(array_diff($m->parts, $done));
        $this->countN($m->sourceType, 'already', count($done));
        $forms = array_values(array_unique(array_map(fn(string $p): int => $this->map['Observation/' . $p]['targetId'], $done)));
        $patient = $m->patientId === null ? null : ($this->map['Patient/' . $m->patientId] ?? null);
        $encounter = $m->encounterId === null ? null : ($this->map['Encounter/' . $m->encounterId] ?? null);
        if (count($forms) > 1 || $patient === null || $encounter === null) {
            $this->skipParts($m, $new, count($forms) > 1 ? self::SPLIT_FORM : ($patient === null ? self::NO_PATIENT : self::NO_ENCOUNTER));
            return;
        }
        $onForm = [];
        foreach ($done as $p) {
            $onForm += $m->partValues[$p] ?? [];
        }
        $values = [];
        $adding = [];
        $kept = [];
        foreach ($new as $p) {
            $fresh = array_diff_key($m->partValues[$p] ?? [], $onForm);
            if ($fresh === []) {
                $kept[] = $p;
                continue;
            }
            $values = $fresh + $values;
            $adding[] = $p;
        }
        if ($kept !== []) {
            $this->skipParts($m, $kept, self::ALREADY_ON_FORM);
        }
        if ($adding === []) {
            return;
        }
        if ($this->dryRun) {
            $this->note($m, $adding, $m->kind, $forms[0], $patient['pid']);
            $this->countN($m->sourceType, 'imported', count($adding));
            return;
        }
        try {
            $this->writer->transaction(function () use ($m, $adding, $values, $forms, $patient, $encounter): void {
                $this->writer->addToVitals($forms[0], $values, $patient['pid'], $encounter['targetId']);
                $this->persist($m, $adding, $m->kind, $forms[0], $patient['pid'], false);
            });
        } catch (\RuntimeException $e) {
            $this->countN($m->sourceType, 'failed', count($adding));
            $this->issues[] = ['type' => $m->sourceType, 'id' => $m->sourceId, 'level' => 'failed', 'message' => $e->getMessage()];
            return;
        }
        $this->note($m, $adding, $m->kind, $forms[0], $patient['pid']);
        $this->countN($m->sourceType, 'imported', count($adding));
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
        $parts = $m->parts === [] ? null : $m->parts;
        if ($persist) {
            $this->persist($m, $parts, $target, $targetId, $pid, $linked);
        }
        $this->note($m, $parts, $target, $targetId, $pid);
    }

    /**
     * The record's keys: itself, or the folded Observations given.
     *
     * @param list<string>|null $parts
     * @return list<array{string, string}>
     */
    private static function keys(Mapped $m, ?array $parts): array
    {
        return $parts === null ? [[$m->sourceType, $m->sourceId]] : array_map(static fn(string $p): array => ['Observation', $p], $parts);
    }

    /**
     * Saves the map entries, inside the record's transaction.
     *
     * @param list<string>|null $parts
     */
    private function persist(Mapped $m, ?array $parts, string $target, int $targetId, int $pid, bool $linked): void
    {
        if ($this->dryRun || !is_callable($this->record)) {
            return;
        }
        foreach (self::keys($m, $parts) as [$type, $id]) {
            ($this->record)($type, $id, $target, $targetId, $pid, $linked);
        }
    }

    /**
     * Keeps the entries for the rest of the run, once they're saved.
     *
     * @param list<string>|null $parts
     */
    private function note(Mapped $m, ?array $parts, string $target, int $targetId, int $pid): void
    {
        foreach (self::keys($m, $parts) as [$type, $id]) {
            $this->map["$type/$id"] = ['target' => $target, 'targetId' => $targetId, 'pid' => $pid];
        }
    }

    private function skip(Mapped $m, string $why, ?string $detail = null): void
    {
        $n = max(1, count($m->parts));
        $this->report[$m->sourceType]['skipped'][$why] = ($this->report[$m->sourceType]['skipped'][$why] ?? 0) + $n;
        $this->issues[] = ['type' => $m->sourceType, 'id' => $m->sourceId, 'level' => 'skipped', 'message' => $detail ?? $why];
    }

    /**
     * Skips some of a record's folded Observations, each with an issue line.
     *
     * @param list<string> $parts
     */
    private function skipParts(Mapped $m, array $parts, string $why): void
    {
        $this->report[$m->sourceType]['skipped'][$why] = ($this->report[$m->sourceType]['skipped'][$why] ?? 0) + count($parts);
        foreach ($parts as $p) {
            $this->issues[] = ['type' => $m->sourceType, 'id' => $p, 'level' => 'skipped', 'message' => $why];
        }
    }

    /** Counts the record, and for folded vitals each Observation in it. */
    private function count(Mapped $m, string $what): void
    {
        $this->countN($m->sourceType, $what, max(1, count($m->parts)));
    }

    private function countN(string $type, string $what, int $n): void
    {
        $this->report[$type] ??= ['read' => 0, 'imported' => 0, 'already' => 0, 'skipped' => [], 'failed' => 0];
        $this->report[$type][$what] += $n;
    }
}
