<?php

/**
 * Small readers for FHIR R4 values as decoded JSON, shared by the mappers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Map;

final class Fhir
{
    public const SNOMED = 'http://snomed.info/sct';
    public const ICD10CM = 'http://hl7.org/fhir/sid/icd-10-cm';
    public const RXNORM = 'http://www.nlm.nih.gov/research/umls/rxnorm';
    public const CVX = 'http://hl7.org/fhir/sid/cvx';
    public const LOINC = 'http://loinc.org';
    public const NPI = 'http://hl7.org/fhir/sid/us-npi';

    /**
     * The codings of a CodeableConcept that have a system and a code.
     *
     * @return list<array{system: string, code: string, display: string}>
     */
    public static function codings(mixed $concept): array
    {
        $out = [];
        foreach (is_array($concept) && is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $c) {
            if (is_array($c) && is_string($c['system'] ?? null) && is_string($c['code'] ?? null) && $c['code'] !== '') {
                $out[] = ['system' => $c['system'], 'code' => $c['code'], 'display' => is_string($c['display'] ?? null) ? $c['display'] : ''];
            }
        }
        return $out;
    }

    /** The first code in a system, or null. */
    public static function code(mixed $concept, string $system): ?string
    {
        foreach (self::codings($concept) as $c) {
            if ($c['system'] === $system) {
                return $c['code'];
            }
        }
        return null;
    }

    /** A CodeableConcept's text, else its first coding's display. */
    public static function text(mixed $concept): string
    {
        if (is_array($concept) && is_string($concept['text'] ?? null) && trim($concept['text']) !== '') {
            return trim($concept['text']);
        }
        foreach (self::codings($concept) as $c) {
            if ($c['display'] !== '') {
                return $c['display'];
            }
        }
        return '';
    }

    /** A FHIR date or dateTime as Y-m-d (the date as written, whatever the offset), or null. */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/', $value, $m)) {
            return null;
        }
        return sprintf('%s-%s-%s', $m[1], $m[2] ?? '01', $m[3] ?? '01');
    }

    /** A FHIR dateTime as Y-m-d H:i:s in its own offset's local time, or the date at midnight. */
    public static function dateTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}(?::\d{2})?)/', $value, $m)) {
            return $m[1] . ' ' . (strlen($m[2]) === 5 ? $m[2] . ':00' : $m[2]);
        }
        $date = self::date($value);
        return $date === null ? null : $date . ' 00:00:00';
    }

    /**
     * The first code of a CodeableConcept used as a status or category (clinicalStatus, verificationStatus,
     * category), or ''. The code is what counts there, so a coding with no system is read too.
     */
    public static function status(mixed $concept): string
    {
        foreach (is_array($concept) && is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $c) {
            if (is_array($c) && is_string($c['code'] ?? null) && $c['code'] !== '') {
                return $c['code'];
            }
        }
        return '';
    }

    /**
     * An extension (or sub-extension) by url.
     *
     * @param array<string, mixed> $resource
     * @return list<array<string, mixed>>
     */
    public static function extensions(array $resource, string $url): array
    {
        $out = [];
        foreach (is_array($resource['extension'] ?? null) ? $resource['extension'] : [] as $e) {
            if (is_array($e) && ($e['url'] ?? null) === $url) {
                $out[] = $e;
            }
        }
        return $out;
    }
}
