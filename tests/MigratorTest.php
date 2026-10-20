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

    private static function export(): FhirExport
    {
        $obs = static fn(string $id, string $loinc, float $v, string $unit, string $category = 'vital-signs'): array => ['resourceType' => 'Observation', 'id' => $id,
            'status' => 'final', 'subject' => ['reference' => 'Patient/p1'], 'encounter' => ['reference' => 'Encounter/e1'], 'category' => [['coding' => [['code' => $category]]]],
            'code' => ['coding' => [['system' => Fhir::LOINC, 'code' => $loinc]]], 'effectiveDateTime' => '2021-11-24', 'valueQuantity' => ['value' => $v, 'code' => $unit]];
        return FhirExport::fromResources([
            ['resourceType' => 'Patient', 'id' => 'p1', 'name' => [['given' => ['Ana'], 'family' => 'Ruiz']], 'gender' => 'female', 'birthDate' => '1980-01-01'],
            ['resourceType' => 'Patient', 'id' => 'p2', 'name' => [['given' => ['Bo'], 'family' => 'Lee']], 'gender' => 'male', 'birthDate' => '1990-01-01'],
            ['resourceType' => 'Encounter', 'id' => 'e1', 'subject' => ['reference' => 'Patient/p1'], 'class' => ['code' => 'AMB'], 'period' => ['start' => '2021-11-24T09:00:00']],
            ['resourceType' => 'Condition', 'id' => 'c1', 'subject' => ['reference' => 'Patient/p1'], 'code' => ['text' => 'Asthma'], 'onsetDateTime' => '2010-01-01'],
            $obs('w', '29463-7', 70, 'kg'), $obs('h', '8302-2', 175, 'cm'), $obs('a1c', '4548-4', 6.1, '%', 'laboratory'),
            ['resourceType' => 'Procedure', 'id' => 'x1', 'subject' => ['reference' => 'Patient/p1']],
        ]);
    }
}
