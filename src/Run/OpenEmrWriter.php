<?php

/**
 * Writes mapped records through OpenEMR's own services, so their validation, uuids and
 * events apply: PatientService, EncounterService (which also writes the encounter's forms
 * row), ConditionService, AllergyIntoleranceService, ListService and VitalsService.
 * Immunizations are written as OpenEMR's Immunizations screen writes them, since 8.4's
 * ImmunizationService::insert is a stub. The patient is checked to exist first: some
 * validators let an unknown patient through.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Run;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\AllergyIntoleranceService;
use OpenEMR\Services\ConditionService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\VitalsService;
use OpenEMR\Validators\ProcessingResult;
use OpenHealthcare\Migration\Map\Mapped;

final class OpenEmrWriter implements Writer
{
    /** @var array<int, string> pid => puuid */
    private array $puuids = [];

    public function __construct(private readonly int $userId, private readonly int $facility)
    {
        require_once OEGlobalsBag::getInstance()->getSrcDir() . '/forms.inc.php';
    }

    public function findPatient(string $fname, string $lname, string $dob, string $sex): ?int
    {
        $row = QueryUtils::fetchRecords(
            'SELECT `pid` FROM `patient_data` WHERE `fname` = ? AND `lname` = ? AND `DOB` = ? AND `sex` = ? ORDER BY `pid` LIMIT 1',
            [$fname, $lname, $dob, $sex],
        )[0] ?? null;
        return $row === null ? null : (int)$row['pid'];
    }

    public function write(Mapped $record, ?int $pid, ?int $encounter): int
    {
        if ($record->kind === Mapped::PATIENT) {
            $data = $record->data;
            if (isset($data['pubpid']) && QueryUtils::fetchRecords('SELECT 1 FROM `patient_data` WHERE `pubpid` = ?', [$data['pubpid']]) !== []) {
                unset($data['pubpid']);
            }
            $result = (new PatientService())->insert($data);
            return (int)$this->first($result, 'pid');
        }
        if ($pid === null || ($puuid = $this->puuid($pid)) === null) {
            throw new \RuntimeException('its patient is not in OpenEMR');
        }
        $user = (string)(QueryUtils::fetchRecords('SELECT `username` FROM `users` WHERE `id` = ?', [$this->userId])[0]['username'] ?? '');
        switch ($record->kind) {
            case Mapped::ENCOUNTER:
                $data = $record->data + ['user' => $user, 'group' => 'Default'];
                $data['facility_id'] = $data['facility_id'] ?: $this->facility;
                $data['billing_facility'] = $data['billing_facility'] ?: $this->facility;
                $result = (new EncounterService())->insertEncounter($puuid, $data);
                return (int)$this->first($result, 'encounter');
            case Mapped::PROBLEM:
                $result = (new ConditionService())->insert($record->data + ['puuid' => $puuid]);
                return (int)$this->first($result, 'id');
            case Mapped::ALLERGY:
                $result = (new AllergyIntoleranceService())->insert($record->data + ['puuid' => $puuid]);
                return (int)$this->first($result, 'id');
            case Mapped::MEDICATION:
                $d = $record->data;
                $id = (new ListService())->insert(['pid' => $pid, 'type' => 'medication', 'title' => $d['title'], 'begdate' => $d['begdate'],
                    'enddate' => $d['enddate'], 'diagnosis' => $d['diagnosis']]);
                if (!is_numeric($id) || (int)$id < 1) {
                    throw new \RuntimeException('the medication list refused it');
                }
                QueryUtils::sqlStatementThrowException('UPDATE `lists` SET `user` = ?, `activity` = 1, `date` = NOW() WHERE `id` = ?', [$user, (int)$id]);
                return (int)$id;
            case Mapped::IMMUNIZATION:
                return $this->immunization($record->data, $pid, $encounter, $user);
            case Mapped::VITALS:
                if ($encounter === null) {
                    throw new \RuntimeException('its encounter is not in OpenEMR');
                }
                $saved = (new VitalsService())->save($record->data + ['pid' => $pid, 'eid' => $encounter, 'authorized' => 1, 'user' => $user, 'groupname' => 'Default', 'activity' => 1]);
                if (!is_numeric($saved['id'] ?? null)) {
                    throw new \RuntimeException('the Vitals form was not saved');
                }
                return (int)$saved['id'];
        }
        throw new \RuntimeException("nothing writes a {$record->kind}");
    }

    /**
     * As interface/patient_file/summary/immunizations.php writes one.
     *
     * @param array<string, mixed> $d
     */
    private function immunization(array $d, int $pid, ?int $encounter, string $user): int
    {
        $uuid = UuidRegistry::getRegistryForTable('immunizations')->createUuid();
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `immunizations` (`uuid`, `patient_id`, `administered_date`, `cvx_code`, `lot_number`, `expiration_date`, `note`,
                 `completion_status`, `information_source`, `encounter_id`, `created_by`, `updated_by`, `create_date`, `update_date`, `added_erroneously`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 0)',
            [$uuid, $pid, $d['administered_date'], $d['cvx_code'], $d['lot_number'], $d['expiration_date'], $d['note'],
                $d['completion_status'], $d['information_source'], $encounter, $this->userId, $this->userId],
        );
    }

    private function puuid(int $pid): ?string
    {
        if (!isset($this->puuids[$pid])) {
            $row = QueryUtils::fetchRecords('SELECT `uuid` FROM `patient_data` WHERE `pid` = ?', [$pid])[0] ?? null;
            if ($row === null || $row['uuid'] === null) {
                return null;
            }
            $this->puuids[$pid] = UuidRegistry::uuidToString($row['uuid']);
        }
        return $this->puuids[$pid];
    }

    /** The first data row's field, or an exception naming what OpenEMR refused. */
    private function first(mixed $result, string $field): int|string
    {
        if (is_array($result) && isset($result[$field])) {
            return $result[$field];
        }
        if (!$result instanceof ProcessingResult) {
            throw new \RuntimeException('OpenEMR returned nothing');
        }
        if (!$result->isValid() || $result->hasInternalErrors()) {
            $messages = [];
            foreach ($result->getValidationMessages() as $field2 => $m) {
                $messages[] = $field2 . ': ' . (is_array($m) ? implode(', ', array_map('strval', $m)) : (string)$m);
            }
            throw new \RuntimeException('OpenEMR refused it: ' . implode('; ', array_merge($messages, $result->getInternalErrors())));
        }
        $data = $result->getData()[0] ?? null;
        if (!is_array($data) || !isset($data[$field])) {
            throw new \RuntimeException("OpenEMR saved it but returned no $field");
        }
        return $data[$field];
    }
}
