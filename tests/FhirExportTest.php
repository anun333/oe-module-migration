<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Migration\Map\Fhir;
use OpenHealthcare\Migration\Source\FhirExport;
use PHPUnit\Framework\TestCase;

final class FhirExportTest extends TestCase
{
    public function testItReadsNdjsonBundlesAndSingleResourcesFromAFolder(): void
    {
        $dir = sys_get_temp_dir() . '/migration-export-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/Patient.ndjson", json_encode(['resourceType' => 'Patient', 'id' => 'p1']) . "\n\n" . json_encode(['resourceType' => 'Patient', 'id' => 'p2']) . "\nnot json\n");
            file_put_contents("$dir/bundle.json", json_encode(['resourceType' => 'Bundle', 'entry' => [
                ['fullUrl' => 'urn:uuid:e1', 'resource' => ['resourceType' => 'Encounter', 'id' => 'e1']],
                ['resource' => ['resourceType' => 'Condition', 'id' => 'c1']],
                ['resource' => ['resourceType' => 'Condition']],
            ]]));
            file_put_contents("$dir/parameters.json", json_encode(['transactionTime' => '2026-10-18']));
            file_put_contents("$dir/broken.json", '{');
            file_put_contents("$dir/notes.txt", 'ignored');

            $export = FhirExport::fromDirectory($dir);

            $this->assertSame(['Condition' => 1, 'Encounter' => 1, 'Patient' => 2], $export->counts());
            $this->assertSame(['Patient.ndjson line 4: not JSON', 'broken.json: not JSON', 'Condition with no id'], $export->problems(), 'in the order the files are read: sorted by name');
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testReferencesResolveInEachFormExportsUse(): void
    {
        $export = FhirExport::fromResources([
            ['resourceType' => 'Bundle', 'entry' => [
                ['fullUrl' => 'urn:uuid:abc', 'resource' => ['resourceType' => 'Patient', 'id' => 'p1']],
                ['fullUrl' => 'urn:uuid:def', 'resource' => ['resourceType' => 'Practitioner', 'id' => 'dr1',
                    'identifier' => [['system' => Fhir::NPI, 'value' => '9999987198']]]],
            ]],
            ['resourceType' => 'Encounter', 'id' => 'e1'],
        ]);

        $this->assertSame('p1', $export->resolve(['reference' => 'urn:uuid:abc'])['id'] ?? null);
        $this->assertSame('p1', $export->resolve(['reference' => 'Patient/p1'])['id'] ?? null);
        $this->assertSame('e1', $export->resolve(['reference' => 'urn:uuid:e1'])['id'] ?? null, 'an NDJSON resource by its id as a urn');
        $this->assertSame('dr1', $export->resolve(['reference' => 'Practitioner?identifier=' . Fhir::NPI . '|9999987198'])['id'] ?? null);
        $this->assertSame('dr1', $export->resolve(['identifier' => ['system' => Fhir::NPI, 'value' => '9999987198']], 'Practitioner')['id'] ?? null);
        $this->assertNull($export->resolve(['reference' => 'Patient/missing']));
        $this->assertSame(['Patient', 'missing'], $export->target(['reference' => 'Patient/missing']), 'the target is known even when the export lacks it');
        $this->assertNull($export->resolve('Patient/p1'));
        $this->assertNull($export->resolve(['reference' => 'https://elsewhere.example/fhir/Patient/p1']));
    }

    public function testFhirValuesAreReadSafely(): void
    {
        $concept = ['coding' => [['system' => Fhir::SNOMED, 'code' => '44054006', 'display' => 'Diabetes'], ['code' => 'no-system'], ['system' => Fhir::ICD10CM, 'code' => 'E11.9']], 'text' => ' '];
        $this->assertSame('E11.9', Fhir::code($concept, Fhir::ICD10CM));
        $this->assertSame('Diabetes', Fhir::text($concept), 'blank text falls back to the display');
        $this->assertCount(2, Fhir::codings($concept), 'a coding with no system is left out');
        $this->assertSame('1967-08-16', Fhir::date('1967-08-16T01:59:48-05:00'));
        $this->assertSame('2020-03-01', Fhir::date('2020-03'));
        $this->assertSame('1967-08-16 01:59:48', Fhir::dateTime('1967-08-16T01:59:48-05:00'), 'the time as written, not moved to another zone');
        $this->assertSame('2021-11-24 00:00:00', Fhir::dateTime('2021-11-24'));
        $this->assertNull(Fhir::date('yesterday'));
        $this->assertNull(Fhir::date(null));
    }
}
