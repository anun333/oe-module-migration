<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Migration\Map\Fhir;
use OpenHealthcare\Migration\Map\Mappers;
use OpenHealthcare\Migration\Run\Migrator;
use OpenHealthcare\Migration\Source\FhirExport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class MigratorTest extends TestCase
{
    public function testADryRunWritesNothingAndCountsWhatWouldBeImported(): void
    {
        $writer = new FakeWriter();
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, [], true))->run(self::export());

        $this->assertSame([], $writer->written);
        $this->assertSame(['read' => 2, 'imported' => 2, 'already' => 0, 'skipped' => [], 'failed' => 0], $result['report']['Patient']);
        $this->assertSame(1, $result['report']['Encounter']['imported']);
        $this->assertSame(1, $result['report']['Condition']['imported']);
        $this->assertSame(['read' => 3, 'imported' => 2, 'already' => 0, 'skipped' => ['a lab result: not imported by this version' => 1], 'failed' => 0], $result['report']['Observation']);
        $this->assertSame([Mappers::WAITING_ON_OPENEMR['Procedure'] => 1], $result['report']['Procedure']['skipped'], 'a procedure waits for an OpenEMR service, and says so');
    }

    public function testAnImportWritesInDependencyOrderAndMapsEachRecord(): void
    {
        $writer = new FakeWriter();
        $mapped = [];
        $migrator = new Migrator(new Mappers(new FakeLookups()), $writer, [], false, false,
            static function (string $type, string $id, string $target, int $targetId, int $pid) use (&$mapped): void {
                $mapped["$type/$id"] = [$target, $targetId, $pid];
            });
        $result = $migrator->run(self::export());

        $this->assertSame(['patient', 'patient', 'encounter', 'problem', 'vitals'], array_column($writer->written, 'kind'));
        $this->assertSame(['pid' => 100, 'encounter' => 1002], ['pid' => $writer->written[4]['pid'], 'encounter' => $writer->written[4]['encounter']], 'vitals go on the imported encounter');
        $this->assertSame(['vitals', 1004, 100], $mapped['Observation/w'], 'each Observation folded into the form is mapped to it');
        $this->assertSame(['vitals', 1004, 100], $mapped['Observation/h']);
        $this->assertSame(2, $result['report']['Observation']['imported']);
    }

    public function testARerunAddsNothing(): void
    {
        $map = [];
        $first = new Migrator(new Mappers(new FakeLookups()), new FakeWriter(), [], false, false,
            static function (string $type, string $id, string $target, int $targetId, int $pid) use (&$map): void {
                $map["$type/$id"] = ['target' => $target, 'targetId' => $targetId, 'pid' => $pid];
            });
        $first->run(self::export());
        $writer = new FakeWriter();
        $again = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false))->run(self::export());

        $this->assertSame([], $writer->written);
        $this->assertSame(2, $again['report']['Patient']['already']);
        $this->assertSame(2, $again['report']['Observation']['already']);
    }

    public function testAPossibleDuplicateIsSkippedUnlessLinked(): void
    {
        $existing = ['Ana|Ruiz|1980-01-01|Female' => 42];
        $writer = new FakeWriter($existing);
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, [], false))->run(self::export());
        $this->assertSame([Migrator::DUPLICATE => 1], $result['report']['Patient']['skipped']);
        $this->assertSame([Migrator::NO_PATIENT => 1], $result['report']['Encounter']['skipped'], 'its encounter waits for the patient');
        $this->assertContains('possible duplicate of pid 42', array_column($result['issues'], 'message'));

        $linked = new FakeWriter($existing);
        $result = (new Migrator(new Mappers(new FakeLookups()), $linked, [], false, true))->run(self::export());
        $this->assertSame(1, $result['report']['Patient']['imported'] - 1, 'linked counts as brought in');
        $this->assertSame(42, $linked->written[1]['pid'], 'the encounter goes on the existing patient');
    }

    public function testAFailureIsReportedWithOpenEmrsReasonAndItsDependentsWait(): void
    {
        $result = (new Migrator(new Mappers(new FakeLookups()), new FakeWriter([], 'p1'), [], false))->run(self::export());
        $this->assertSame(1, $result['report']['Patient']['failed']);
        $this->assertContains('OpenEMR refused it: lname: too short', array_column($result['issues'], 'message'));
        $this->assertSame([Migrator::NO_PATIENT => 1], $result['report']['Encounter']['skipped']);
    }

    public function testALaterExportAddsANewReadingToTheVitalsFormAlreadyImported(): void
    {
        $map = self::mapAfterFirstRun();
        $writer = new FakeWriter();
        $mapped = [];
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false, false,
            static function (string $type, string $id, string $target, int $targetId, int $pid) use (&$mapped): void {
                $mapped["$type/$id"] = [$target, $targetId, $pid];
            }))->run(self::export([self::obs('p', '8867-4', 72, '/min')]));

        $this->assertSame([['kind' => 'vitals-add', 'id' => '1004', 'pid' => 100, 'encounter' => 1002, 'values' => ['pulse' => 72.0]]], $writer->written,
            'only the new pulse goes onto the Vitals form already imported; no second form');
        $this->assertSame(['Observation/p' => ['vitals', 1004, 100]], $mapped, 'the new reading is mapped to that form');
        $this->assertSame(2, $result['report']['Observation']['already']);
        $this->assertSame(1, $result['report']['Observation']['imported']);
    }

    public function testALaterReadingOfAVitalSignTheFormAlreadyHasIsLeftOut(): void
    {
        $map = self::mapAfterFirstRun();
        $writer = new FakeWriter();
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false))
            ->run(self::export([self::obs('w2', '29463-7', 72, 'kg')]));

        $this->assertSame([], $writer->written, 'the weight already on the form stays as it is');
        $this->assertSame(1, $result['report']['Observation']['skipped'][Migrator::ALREADY_ON_FORM] ?? 0);
        $this->assertContains('w2', array_column($result['issues'], 'id'), 'with an issue line naming it');
        $this->assertSame(2, $result['report']['Observation']['already']);
    }

    public function testAValueEnteredOnTheFormAfterTheImportIsKept(): void
    {
        $map = self::mapAfterFirstRun();
        $writer = new FakeWriter([], null, null, [1004 => ['weight' => '154.32', 'height' => '68.90', 'pulse' => '80.00']]);
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false))
            ->run(self::export([self::obs('p', '8867-4', 72, '/min')]));

        $this->assertSame([], $writer->written, 'the pulse someone entered on the form after the import stays');
        $this->assertSame(1, $result['report']['Observation']['skipped'][Migrator::ALREADY_ON_FORM] ?? 0);
        $this->assertContains('p', array_column($result['issues'], 'id'));
    }

    public function testAReadingTheLaterExportNoLongerCarriesIsKept(): void
    {
        $map = self::mapAfterFirstRun();
        $writer = new FakeWriter([], null, null, [1004 => ['weight' => '154.32', 'height' => '68.90']]);
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false))
            ->run(self::export([self::obs('w2', '29463-7', 72, 'kg')], ['w']));

        $this->assertSame([], $writer->written, 'the first export\'s weight is on the form, though this export no longer carries it');
        $this->assertSame(1, $result['report']['Observation']['skipped'][Migrator::ALREADY_ON_FORM] ?? 0);
        $this->assertSame(1, $result['report']['Observation']['already'], 'only the height is in this export and mapped');
    }

    public function testABlankFieldOnTheStoredFormIsFilled(): void
    {
        $map = self::mapAfterFirstRun();
        $writer = new FakeWriter([], null, null, [1004 => ['weight' => '154.32', 'height' => '68.90', 'pulse' => '0.00', 'respiration' => null]]);
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, $map, false))
            ->run(self::export([self::obs('p', '8867-4', 72, '/min')]));

        $this->assertSame([['kind' => 'vitals-add', 'id' => '1004', 'pid' => 100, 'encounter' => 1002, 'values' => ['pulse' => 72.0]]], $writer->written,
            'a 0 or NULL in a column is nothing there yet');
        $this->assertSame(1, $result['report']['Observation']['imported']);
    }

    public function testARecordThatFailsPartWayLeavesNothingAndARerunWritesItOnce(): void
    {
        $writer = new FakeWriter([], null, 'c1');
        $mapped = [];
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, [], false, false,
            static function (string $type, string $id) use (&$mapped): void {
                $mapped[] = "$type/$id";
            }))->run(self::export());

        $this->assertNotContains('c1', array_column($writer->written, 'id'), 'the half-written problem is rolled back');
        $this->assertNotContains('Condition/c1', $mapped);
        $this->assertSame(1, $result['report']['Condition']['failed']);
        $this->assertSame(1, $writer->rolledBack);
    }

    public function testAMapEntryThatFailsRollsItsRecordBackAndTheRunGoesOn(): void
    {
        $writer = new FakeWriter();
        $result = (new Migrator(new Mappers(new FakeLookups()), $writer, [], false, false,
            static function (string $type, string $id): void {
                if ("$type/$id" === 'Condition/c1') {
                    throw new \RuntimeException('the map entry was not saved');
                }
            }))->run(self::export());

        $this->assertNotContains('c1', array_column($writer->written, 'id'), 'without its map entry the problem would be written again on a rerun');
        $this->assertSame(1, $result['report']['Condition']['failed']);
        $this->assertContains('the map entry was not saved', array_column($result['issues'], 'message'));
        $this->assertSame(2, $result['report']['Observation']['imported'], 'the run went on to the vitals');
    }

    /** @return array<string, array{target: string, targetId: int, pid: int}> */
    private static function mapAfterFirstRun(): array
    {
        $map = [];
        (new Migrator(new Mappers(new FakeLookups()), new FakeWriter(), [], false, false,
            static function (string $type, string $id, string $target, int $targetId, int $pid) use (&$map): void {
                $map["$type/$id"] = ['target' => $target, 'targetId' => $targetId, 'pid' => $pid];
            }))->run(self::export());
        return $map;
    }

    /** @return array<string, mixed> */
    private static function obs(string $id, string $loinc, float $v, string $unit, string $category = 'vital-signs'): array
    {
        return ['resourceType' => 'Observation', 'id' => $id,
            'status' => 'final', 'subject' => ['reference' => 'Patient/p1'], 'encounter' => ['reference' => 'Encounter/e1'], 'category' => [['coding' => [['code' => $category]]]],
            'code' => ['coding' => [['system' => Fhir::LOINC, 'code' => $loinc]]], 'effectiveDateTime' => '2021-11-24', 'valueQuantity' => ['value' => $v, 'code' => $unit]];
    }

    /**
     * @param list<array<string, mixed>> $extra
     * @param list<string>               $without ids of the default resources to leave out
     */
    private static function export(array $extra = [], array $without = []): FhirExport
    {
        $obs = self::obs(...);
        return FhirExport::fromResources(array_values(array_filter([
            ['resourceType' => 'Patient', 'id' => 'p1', 'name' => [['given' => ['Ana'], 'family' => 'Ruiz']], 'gender' => 'female', 'birthDate' => '1980-01-01'],
            ['resourceType' => 'Patient', 'id' => 'p2', 'name' => [['given' => ['Bo'], 'family' => 'Lee']], 'gender' => 'male', 'birthDate' => '1990-01-01'],
            ['resourceType' => 'Encounter', 'id' => 'e1', 'subject' => ['reference' => 'Patient/p1'], 'class' => ['code' => 'AMB'], 'period' => ['start' => '2021-11-24T09:00:00']],
            ['resourceType' => 'Condition', 'id' => 'c1', 'subject' => ['reference' => 'Patient/p1'], 'code' => ['text' => 'Asthma'], 'onsetDateTime' => '2010-01-01'],
            $obs('w', '29463-7', 70, 'kg'), $obs('h', '8302-2', 175, 'cm'), $obs('a1c', '4548-4', 6.1, '%', 'laboratory'),
            ['resourceType' => 'Procedure', 'id' => 'x1', 'subject' => ['reference' => 'Patient/p1']],
            ...$extra,
        ], static fn(array $r): bool => !in_array($r['id'], $without, true))));
    }
}
