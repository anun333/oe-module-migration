<?php

/**
 * FHIR R4 (US Core) resources to the fields OpenEMR's own services take, as read on
 * 8.4.1 and master (2026-10-18):
 *  - Patient: PatientService::insert (sex Male, Female or Unknown; race and ethnicity
 *    as the lists' option ids, found by their CDC codes; language by ISO code);
 *  - Encounter: EncounterService::insertEncounter (class from _ActEncounterCode);
 *  - Condition: ConditionService::insert (lists medical_problem, classified Diagnosis
 *    so the quality measures read it); AllergyIntolerance: AllergyIntoleranceService;
 *  - MedicationRequest and MedicationStatement: the medication list (ListService);
 *  - Immunization: the immunizations table, as OpenEMR's Immunizations screen writes it;
 *  - vital-sign Observations: one Vitals form per encounter, in the units OpenEMR keeps
 *    (pounds, inches, degrees Fahrenheit).
 * Codes are written as OpenEMR writes them: "SNOMED-CT:", "ICD10:", "RXCUI:".
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Map;

use OpenHealthcare\Migration\Source\FhirExport;

final class Mappers
{
    /** The types version 1 imports. */
    public const TYPES = ['Patient', 'Encounter', 'Condition', 'AllergyIntolerance', 'MedicationRequest', 'MedicationStatement', 'Immunization', 'Observation'];

    /** Read to fill in other records (a medication's name, a provider's NPI), not imported themselves. */
    public const REFERENCED_TYPES = ['Medication', 'Practitioner', 'PractitionerRole', 'Organization', 'Location'];

    /** Those needing only their patient, in the order they're imported. */
    public const CHART_TYPES = ['Condition', 'AllergyIntolerance', 'MedicationRequest', 'MedicationStatement', 'Immunization'];

    public const RACE = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-race';
    public const ETHNICITY = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-ethnicity';

    private const ENCOUNTER_CLASSES = ['ACUTE', 'AMB', 'EMER', 'FLD', 'HH', 'IMP', 'NONAC', 'OBSENC', 'PRENC', 'SS', 'VR'];
    private const MARITAL = ['M' => 'married', 'S' => 'single', 'D' => 'divorced', 'W' => 'widowed', 'L' => 'separated', 'T' => 'domestic partner'];

    /**
     * Vital-sign LOINC codes to the Vitals form's fields, with the conversion to OpenEMR's units.
     * Each is [field, from-unit => factor or 'F'].
     */
    private const VITALS = [
        '8480-6' => 'bps', '8462-4' => 'bpd', '29463-7' => 'weight', '8302-2' => 'height', '8310-5' => 'temperature',
        '8867-4' => 'pulse', '9279-1' => 'respiration', '59408-5' => 'oxygen_saturation', '2708-6' => 'oxygen_saturation',
        '39156-5' => 'BMI', '9843-4' => 'head_circ', '8287-5' => 'head_circ', '8280-0' => 'waist_circ',
    ];

    public function __construct(private readonly Lookups $lookups)
    {
    }

    /** @param array<string, mixed> $p */
    public function patient(array $p): Mapped
    {
        $id = (string)$p['id'];
        $notes = [];
        $name = self::preferredName($p['name'] ?? null);
        $given = array_values(array_filter(is_array($name['given'] ?? null) ? $name['given'] : [], 'is_string'));
        $fname = trim($given[0] ?? '');
        $lname = trim(is_string($name['family'] ?? null) ? $name['family'] : '');
        $dob = Fhir::date($p['birthDate'] ?? null);
        if ($fname === '' || $lname === '' || $dob === null) {
            return Mapped::skipped(Mapped::PATIENT, 'Patient', $id, $id, 'no first name, last name or birth date');
        }
        $sex = match ($p['gender'] ?? null) {
            'male' => 'Male',
            'female' => 'Female',
            default => 'Unknown',
        };
        if ($sex === 'Unknown') {
            $notes[] = 'sex recorded as Unknown, as OpenEMR\'s FHIR parser does: its patient service refuses the list\'s UNK';
        }
        $data = [
            'fname' => $fname,
            'mname' => trim(implode(' ', array_slice($given, 1))),
            'lname' => $lname,
            'title' => is_array($name['prefix'] ?? null) && is_string($name['prefix'][0] ?? null) ? $name['prefix'][0] : '',
            'DOB' => $dob,
            'sex' => $sex,
        ];
        $address = self::preferred($p['address'] ?? null, 'home');
        if ($address !== null) {
            $lines = array_filter(is_array($address['line'] ?? null) ? $address['line'] : [], 'is_string');
            $data += [
                'street' => implode(', ', $lines),
                'city' => (string)($address['city'] ?? ''),
                'state' => (string)($address['state'] ?? ''),
                'postal_code' => (string)($address['postalCode'] ?? ''),
                'country_code' => (string)($address['country'] ?? ''),
            ];
        }
        foreach (is_array($p['telecom'] ?? null) ? $p['telecom'] : [] as $t) {
            $value = is_array($t) && is_string($t['value'] ?? null) ? trim($t['value']) : '';
            if ($value === '') {
                continue;
            }
            if (($t['system'] ?? null) === 'email') {
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $notes[] = 'an email address OpenEMR\'s validator refuses was left out';
                    continue;
                }
                $data['email'] ??= $value;
            } elseif (($t['system'] ?? null) === 'phone') {
                $field = match ($t['use'] ?? null) {
                    'mobile' => 'phone_cell',
                    'work' => 'phone_biz',
                    default => 'phone_home',
                };
                $data[$field] ??= $value;
            }
        }
        foreach (is_array($p['communication'] ?? null) ? $p['communication'] : [] as $c) {
            foreach (Fhir::codings($c['language'] ?? null) as $coding) {
                $option = $this->lookups->language(strtolower(substr($coding['code'], 0, 2)));
                if ($option !== null) {
                    $data['language'] ??= $option;
                }
            }
        }
        $race = $this->ombCodes($p, self::RACE, $this->lookups->race(...));
        if ($race !== []) {
            $data['race'] = implode('|', $race);
        }
        $ethnicity = $this->ombCodes($p, self::ETHNICITY, $this->lookups->ethnicity(...));
        if ($ethnicity !== []) {
            $data['ethnicity'] = $ethnicity[0];
        }
        $marital = self::MARITAL[Fhir::status($p['maritalStatus'] ?? null)] ?? null;
        if ($marital !== null) {
            $data['status'] = $marital;
        }
        foreach (is_array($p['identifier'] ?? null) ? $p['identifier'] : [] as $identifier) {
            $kind = Fhir::code($identifier['type'] ?? null, 'http://terminology.hl7.org/CodeSystem/v2-0203');
            $value = is_string($identifier['value'] ?? null) ? $identifier['value'] : '';
            if ($kind === 'MR' && $value !== '') {
                $data['pubpid'] ??= substr($value, 0, 255);
            } elseif ($kind === 'SS' && $value !== '') {
                $data['ss'] ??= $value;
            }
        }
        $deceased = Fhir::dateTime($p['deceasedDateTime'] ?? null);
        if ($deceased !== null) {
            $data['deceased_date'] = $deceased;
        } elseif (($p['deceasedBoolean'] ?? false) === true) {
            $notes[] = 'deceased, with no date: OpenEMR needs a date of death, so it was left out';
        }
        return new Mapped(Mapped::PATIENT, 'Patient', $id, $id, null, $data, null, $notes);
    }

    /** @param array<string, mixed> $e */
    public function encounter(array $e, FhirExport $export): Mapped
    {
        $id = (string)$e['id'];
        $patient = $this->patientOf($e['subject'] ?? null, $export);
        $start = Fhir::dateTime($e['period']['start'] ?? null);
        if ($start === null) {
            return Mapped::skipped(Mapped::ENCOUNTER, 'Encounter', $id, $patient, 'no start date');
        }
        $notes = [];
        $class = is_array($e['class'] ?? null) && is_string($e['class']['code'] ?? null) ? $e['class']['code'] : '';
        if (!in_array($class, self::ENCOUNTER_CLASSES, true)) {
            $notes[] = "class $class isn't in OpenEMR's list: recorded as AMB";
            $class = 'AMB';
        }
        $reason = '';
        foreach (is_array($e['reasonCode'] ?? null) ? $e['reasonCode'] : [] as $rc) {
            $reason = $reason !== '' ? $reason : Fhir::text($rc);
        }
        $type = is_array($e['type'] ?? null) ? ($e['type'][0] ?? null) : null;
        $reason = $reason !== '' ? $reason : Fhir::text($type);
        $provider = 0;
        foreach (is_array($e['participant'] ?? null) ? $e['participant'] : [] as $participant) {
            $practitioner = $export->resolve($participant['individual'] ?? null, 'Practitioner');
            $npi = self::npi($practitioner) ?? self::npiInReference($participant['individual'] ?? null);
            $user = $npi === null ? null : $this->lookups->userByNpi($npi);
            if ($user !== null) {
                $provider = $user;
                break;
            }
        }
        if ($provider === 0) {
            $notes[] = 'its provider is not an OpenEMR user: the encounter has none';
        }
        return new Mapped(Mapped::ENCOUNTER, 'Encounter', $id, $patient, null, [
            'date' => $start,
            'date_end' => Fhir::dateTime($e['period']['end'] ?? null) ?? '',
            'class_code' => $class,
            'reason' => $reason,
            'pc_catid' => $this->lookups->visitCategory(),
            'facility_id' => $this->lookups->facility(),
            'billing_facility' => $this->lookups->facility(),
            'provider_id' => $provider,
            'sensitivity' => 'normal',
        ], null, $notes);
    }

    /** @param array<string, mixed> $r */
    public function chart(array $r, FhirExport $export): Mapped
    {
        return match ($r['resourceType']) {
            'Condition' => $this->condition($r, $export),
            'AllergyIntolerance' => $this->allergy($r, $export),
            'MedicationRequest', 'MedicationStatement' => $this->medication($r, $export),
            'Immunization' => $this->immunization($r, $export),
            default => Mapped::skipped('', (string)$r['resourceType'], (string)$r['id'], null, 'not imported by this version'),
        };
    }

    /**
     * Vital-sign Observations, one Vitals form per encounter, and every other Observation skipped with its reason.
     *
     * @param list<array<string, mixed>> $observations
     * @return list<Mapped>
     */
    public function vitals(array $observations, FhirExport $export): array
    {
        $out = [];
        $groups = [];
        foreach ($observations as $o) {
            $id = (string)$o['id'];
            $patient = $this->patientOf($o['subject'] ?? null, $export);
            $category = '';
            foreach (is_array($o['category'] ?? null) ? $o['category'] : [] as $c) {
                $category = $category !== '' ? $category : Fhir::status($c);
            }
            if ($category !== 'vital-signs') {
                $out[] = Mapped::skipped(Mapped::VITALS, 'Observation', $id, $patient, match ($category) {
                    'laboratory' => 'a lab result: not imported by this version',
                    'survey', 'social-history' => 'a ' . $category . ' observation: not imported by this version',
                    default => 'not a vital sign: not imported by this version',
                });
                continue;
            }
            if (in_array($o['status'] ?? null, ['entered-in-error', 'cancelled'], true)) {
                $out[] = Mapped::skipped(Mapped::VITALS, 'Observation', $id, $patient, 'marked entered in error or cancelled');
                continue;
            }
            $values = $this->vitalValues($o);
            if ($values === []) {
                $out[] = Mapped::skipped(Mapped::VITALS, 'Observation', $id, $patient, 'a vital sign the Vitals form has no field for');
                continue;
            }
            $encounter = $export->target($o['encounter'] ?? null, 'Encounter');
            if ($encounter === null) {
                $out[] = Mapped::skipped(Mapped::VITALS, 'Observation', $id, $patient, 'not tied to an encounter');
                continue;
            }
            $key = $encounter[1];
            $groups[$key] ??= ['patient' => $patient, 'date' => '', 'data' => [], 'parts' => [], 'notes' => []];
            $groups[$key]['parts'][] = $id;
            $when = Fhir::dateTime($o['effectiveDateTime'] ?? null) ?? '';
            $groups[$key]['date'] = max($groups[$key]['date'], $when);
            foreach ($values as $field => [$value, $note]) {
                if (isset($groups[$key]['data'][$field]) && $groups[$key]['data'][$field] !== $value) {
                    $groups[$key]['notes'][] = "two {$field} readings in one encounter: the later one kept";
                }
                $groups[$key]['data'][$field] = $value;
                if ($note !== null) {
                    $groups[$key]['notes'][] = $note;
                }
            }
        }
        foreach ($groups as $encounter => $g) {
            $out[] = new Mapped(Mapped::VITALS, 'Observation', 'vitals-' . $encounter, $g['patient'], $encounter,
                ['date' => $g['date']] + $g['data'], null, array_values(array_unique($g['notes'])), $g['parts']);
        }
        return $out;
    }

    /** @param array<string, mixed> $c */
    private function condition(array $c, FhirExport $export): Mapped
    {
        $id = (string)$c['id'];
        $patient = $this->patientOf($c['subject'] ?? null, $export);
        $verification = Fhir::status($c['verificationStatus'] ?? null);
        if ($verification === 'entered-in-error') {
            return Mapped::skipped(Mapped::PROBLEM, 'Condition', $id, $patient, 'marked entered in error');
        }
        $title = Fhir::text($c['code'] ?? null);
        $begin = Fhir::date($c['onsetDateTime'] ?? ($c['onsetPeriod']['start'] ?? null)) ?? Fhir::date($c['recordedDate'] ?? null);
        if ($title === '' || $begin === null) {
            return Mapped::skipped(Mapped::PROBLEM, 'Condition', $id, $patient, 'no name, or no onset or recorded date (OpenEMR needs both)');
        }
        $data = [
            'title' => mb_substr($title, 0, 255),
            'begdate' => $begin,
            'diagnosis' => self::codes($c['code'] ?? null),
            'subtype' => 'diagnosis',
            'verification' => in_array($verification, ['confirmed', 'unconfirmed', 'refuted', 'provisional', 'differential'], true) ? $verification : '',
        ];
        $end = Fhir::date($c['abatementDateTime'] ?? ($c['abatementPeriod']['end'] ?? null));
        if ($end !== null) {
            $data['enddate'] = $end;
        }
        if (in_array(Fhir::status($c['clinicalStatus'] ?? null), ['resolved', 'remission'], true)) {
            $data['outcome'] = '1';
        }
        return new Mapped(Mapped::PROBLEM, 'Condition', $id, $patient, $this->encounterOf($c, $export), $data);
    }

    /** @param array<string, mixed> $a */
    private function allergy(array $a, FhirExport $export): Mapped
    {
        $id = (string)$a['id'];
        $patient = $this->patientOf($a['patient'] ?? null, $export);
        $verification = Fhir::status($a['verificationStatus'] ?? null);
        if ($verification === 'entered-in-error') {
            return Mapped::skipped(Mapped::ALLERGY, 'AllergyIntolerance', $id, $patient, 'marked entered in error');
        }
        $title = Fhir::text($a['code'] ?? null);
        if ($title === '') {
            return Mapped::skipped(Mapped::ALLERGY, 'AllergyIntolerance', $id, $patient, 'no substance named');
        }
        $data = [
            'title' => mb_substr($title, 0, 255),
            'diagnosis' => self::codes($a['code'] ?? null),
            'verification' => in_array($verification, ['confirmed', 'unconfirmed', 'refuted'], true) ? $verification : '',
        ];
        $begin = Fhir::date($a['onsetDateTime'] ?? null) ?? Fhir::date($a['recordedDate'] ?? null);
        if ($begin !== null) {
            $data['begdate'] = $begin;
        }
        $notes = [];
        $manifestations = [];
        foreach (is_array($a['reaction'] ?? null) ? $a['reaction'] : [] as $reaction) {
            foreach (is_array($reaction['manifestation'] ?? null) ? $reaction['manifestation'] : [] as $m) {
                $snomed = Fhir::code($m, Fhir::SNOMED);
                $option = $snomed === null ? null : $this->lookups->reaction($snomed);
                if ($option !== null && !isset($data['reaction'])) {
                    $data['reaction'] = $option;
                } elseif (Fhir::text($m) !== '') {
                    $manifestations[] = Fhir::text($m);
                }
            }
            if (in_array($reaction['severity'] ?? null, ['mild', 'moderate', 'severe'], true)) {
                $data['severity_al'] ??= $reaction['severity'];
            }
        }
        if ($manifestations !== []) {
            $data['comments'] = 'Reaction: ' . implode(', ', array_unique($manifestations));
            $notes[] = 'reactions not in OpenEMR\'s reaction list are kept in the comments';
        }
        return new Mapped(Mapped::ALLERGY, 'AllergyIntolerance', $id, $patient, null, $data, null, $notes);
    }

    /** @param array<string, mixed> $m */
    private function medication(array $m, FhirExport $export): Mapped
    {
        $type = (string)$m['resourceType'];
        $id = (string)$m['id'];
        $patient = $this->patientOf($m['subject'] ?? null, $export);
        $status = (string)($m['status'] ?? '');
        if ($status === 'entered-in-error') {
            return Mapped::skipped(Mapped::MEDICATION, $type, $id, $patient, 'marked entered in error');
        }
        $concept = $m['medicationCodeableConcept'] ?? null;
        if ($concept === null && is_array($m['medicationReference'] ?? null)) {
            $concept = $export->resolve($m['medicationReference'], 'Medication')['code'] ?? null;
        }
        $title = Fhir::text($concept);
        if ($title === '') {
            return Mapped::skipped(Mapped::MEDICATION, $type, $id, $patient, 'no medication named');
        }
        $begin = $type === 'MedicationRequest'
            ? Fhir::date($m['authoredOn'] ?? null)
            : (Fhir::date($m['effectivePeriod']['start'] ?? null) ?? Fhir::date($m['effectiveDateTime'] ?? null) ?? Fhir::date($m['dateAsserted'] ?? null));
        $rxcui = Fhir::code($concept, Fhir::RXNORM);
        $data = ['title' => mb_substr($title, 0, 255), 'begdate' => $begin, 'enddate' => null, 'diagnosis' => $rxcui === null ? '' : 'RXCUI:' . $rxcui];
        $notes = [];
        if (in_array($status, ['stopped', 'completed', 'cancelled', 'not-taken'], true)) {
            $end = Fhir::date($m['dispenseRequest']['validityPeriod']['end'] ?? null) ?? Fhir::date($m['effectivePeriod']['end'] ?? null);
            $data['enddate'] = $end ?? $begin;
            if ($end === null) {
                $notes[] = "$status with no end date: ended on its start date, so the list doesn't show it as current";
            }
        }
        return new Mapped(Mapped::MEDICATION, $type, $id, $patient, $this->encounterOf($m, $export), $data, null, $notes);
    }

    /** @param array<string, mixed> $i */
    private function immunization(array $i, FhirExport $export): Mapped
    {
        $id = (string)$i['id'];
        $patient = $this->patientOf($i['patient'] ?? null, $export);
        $status = (string)($i['status'] ?? '');
        if ($status === 'entered-in-error') {
            return Mapped::skipped(Mapped::IMMUNIZATION, 'Immunization', $id, $patient, 'marked entered in error');
        }
        $cvx = Fhir::code($i['vaccineCode'] ?? null, Fhir::CVX);
        $when = Fhir::dateTime($i['occurrenceDateTime'] ?? null);
        if ($cvx === null || !preg_match('/^\d{1,4}$/', $cvx) || $when === null) {
            return Mapped::skipped(Mapped::IMMUNIZATION, 'Immunization', $id, $patient, 'no CVX code or no date given');
        }
        $data = [
            'cvx_code' => $cvx,
            'administered_date' => $when,
            'completion_status' => $status === 'not-done' ? 'Not_Administered' : 'Completed',
            'information_source' => ($i['primarySource'] ?? true) === false ? 'hist_inf_src_unspecified' : 'new_immunization_record',
            'lot_number' => is_string($i['lotNumber'] ?? null) ? $i['lotNumber'] : '',
            'expiration_date' => Fhir::date($i['expirationDate'] ?? null),
            'note' => Fhir::text($i['vaccineCode'] ?? null),
        ];
        return new Mapped(Mapped::IMMUNIZATION, 'Immunization', $id, $patient, $this->encounterOf($i, $export), $data);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, array{float, ?string}> field => [value in OpenEMR's unit, note]
     */
    private function vitalValues(array $o): array
    {
        $out = [];
        $components = is_array($o['component'] ?? null) && $o['component'] !== [] ? $o['component'] : [$o];
        foreach ($components as $part) {
            $loinc = Fhir::code($part['code'] ?? null, Fhir::LOINC);
            $field = $loinc === null ? null : (self::VITALS[$loinc] ?? null);
            $quantity = is_array($part['valueQuantity'] ?? null) ? $part['valueQuantity'] : null;
            if ($field === null || $quantity === null || !is_numeric($quantity['value'] ?? null)) {
                continue;
            }
            $converted = self::toOpenEmrUnit($field, (float)$quantity['value'], (string)($quantity['code'] ?? $quantity['unit'] ?? ''));
            if ($converted !== null) {
                $out[$field] = $converted;
            }
        }
        return $out;
    }

    /** @return array{float, ?string}|null the value in OpenEMR's unit for the field, and a note when it was converted */
    private static function toOpenEmrUnit(string $field, float $value, string $unit): ?array
    {
        if ($value <= 0) {
            return null;
        }
        return match ($field) {
            'weight' => match ($unit) {
                'kg' => [round($value * 2.2046226218, 2), 'weight converted from kg to lb'],
                'g' => [round($value / 1000 * 2.2046226218, 2), 'weight converted from g to lb'],
                '[lb_av]', 'lb' => [round($value, 2), null],
                default => null,
            },
            'height', 'head_circ', 'waist_circ' => match ($unit) {
                'cm' => [round($value / 2.54, 2), "$field converted from cm to in"],
                'm' => [round($value * 100 / 2.54, 2), "$field converted from m to in"],
                '[in_i]', 'in' => [round($value, 2), null],
                default => null,
            },
            'temperature' => match ($unit) {
                'Cel' => [round($value * 9 / 5 + 32, 1), 'temperature converted from Celsius to Fahrenheit'],
                '[degF]', 'degF' => [round($value, 1), null],
                default => null,
            },
            default => [round($value, 2), null],
        };
    }

    /** The source patient a reference points at, by id. */
    private function patientOf(mixed $reference, FhirExport $export): ?string
    {
        $target = $export->target($reference, 'Patient');
        return $target !== null && $target[0] === 'Patient' ? $target[1] : null;
    }

    /** @param array<string, mixed> $r */
    private function encounterOf(array $r, FhirExport $export): ?string
    {
        $target = $export->target($r['encounter'] ?? ($r['context'] ?? null), 'Encounter');
        return $target !== null && $target[0] === 'Encounter' ? $target[1] : null;
    }

    /** Codes as OpenEMR writes them: "SNOMED-CT:...;ICD10:...". */
    private static function codes(mixed $concept): string
    {
        $out = [];
        foreach (Fhir::codings($concept) as $c) {
            $prefix = match ($c['system']) {
                Fhir::SNOMED => 'SNOMED-CT',
                Fhir::ICD10CM, 'http://hl7.org/fhir/sid/icd-10' => 'ICD10',
                Fhir::RXNORM => 'RXCUI',
                Fhir::LOINC => 'LOINC',
                default => null,
            };
            if ($prefix !== null) {
                $out[] = $prefix . ':' . $c['code'];
            }
        }
        return mb_substr(implode(';', array_unique($out)), 0, 255);
    }

    /** @param callable(string): ?string $lookup */
    private function ombCodes(array $p, string $url, callable $lookup): array
    {
        $out = [];
        foreach (Fhir::extensions($p, $url) as $ext) {
            foreach (is_array($ext['extension'] ?? null) ? $ext['extension'] : [] as $sub) {
                if (($sub['url'] ?? null) !== 'ombCategory' || !is_array($sub['valueCoding'] ?? null)) {
                    continue;
                }
                $option = is_string($sub['valueCoding']['code'] ?? null) ? $lookup($sub['valueCoding']['code']) : null;
                if ($option !== null) {
                    $out[] = $option;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string, mixed>|null */
    private static function preferredName(mixed $names): ?array
    {
        $official = self::preferred($names, 'official');
        return $official ?? (is_array($names) && is_array($names[0] ?? null) ? $names[0] : null);
    }

    /** @return array<string, mixed>|null the first with this use, else the first */
    private static function preferred(mixed $list, string $use): ?array
    {
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $item) {
            if (is_array($item) && ($item['use'] ?? null) === $use) {
                return $item;
            }
        }
        return is_array($list[0] ?? null) ? $list[0] : null;
    }

    /** @param array<string, mixed>|null $practitioner */
    private static function npi(?array $practitioner): ?string
    {
        foreach (is_array($practitioner['identifier'] ?? null) ? $practitioner['identifier'] : [] as $identifier) {
            if (($identifier['system'] ?? null) === Fhir::NPI && is_string($identifier['value'] ?? null)) {
                return $identifier['value'];
            }
        }
        return null;
    }

    private static function npiInReference(mixed $reference): ?string
    {
        $ref = is_array($reference) ? ($reference['reference'] ?? '') : '';
        return is_string($ref) && preg_match('#identifier=' . preg_quote(Fhir::NPI, '#') . '\|(\d{10})$#', $ref, $m) ? $m[1] : null;
    }
}
