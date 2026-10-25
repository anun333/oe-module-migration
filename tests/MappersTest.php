<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Migration\Map\Fhir;
use OpenHealthcare\Migration\Map\Mapped;
use OpenHealthcare\Migration\Map\Mappers;
use OpenHealthcare\Migration\Source\FhirExport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class MappersTest extends TestCase
{
    private const SUBJECT = ['reference' => 'Patient/p1'];

    public function testAUsCorePatient(): void
    {
        $m = $this->mappers()->patient([
            'resourceType' => 'Patient', 'id' => 'p1',
            'extension' => [
                ['url' => Mappers::RACE, 'extension' => [['url' => 'ombCategory', 'valueCoding' => ['code' => '2106-3']], ['url' => 'ombCategory', 'valueCoding' => ['code' => '2028-9']], ['url' => 'text', 'valueString' => 'White, Asian']]],
                ['url' => Mappers::ETHNICITY, 'extension' => [['url' => 'ombCategory', 'valueCoding' => ['code' => '2135-2']]]],
            ],
            'name' => [['use' => 'usual', 'given' => ['Ray'], 'family' => 'Fahey'], ['use' => 'official', 'given' => ['Raymon', 'J'], 'family' => 'Fahey', 'prefix' => ['Mr.']]],
            'gender' => 'male', 'birthDate' => '1975-02-03',
            'address' => [['use' => 'old', 'line' => ['1 Old St']], ['use' => 'home', 'line' => ['1023 Klocko Camp', 'Suite 15'], 'city' => 'Malden', 'state' => 'MA', 'postalCode' => '02148', 'country' => 'US']],
            'telecom' => [['system' => 'phone', 'value' => '555-454-5317', 'use' => 'home'], ['system' => 'phone', 'value' => '555-000-1111', 'use' => 'mobile'],
                ['system' => 'email', 'value' => 'Arturo.Valentín@example.com'], ['system' => 'email', 'value' => 'ray@example.com']],
            'communication' => [['language' => ['coding' => [['system' => 'urn:ietf:bcp:47', 'code' => 'es-MX']]]]],
            'maritalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-MaritalStatus', 'code' => 'M']]],
            'identifier' => [['type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'MR']]], 'value' => 'MRN-77'],
                ['type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'SS']]], 'value' => '999-99-6377']],
        ]);

        $this->assertNull($m->skip);
        $this->assertSame([
            'fname' => 'Raymon', 'mname' => 'J', 'lname' => 'Fahey', 'title' => 'Mr.', 'DOB' => '1975-02-03', 'sex' => 'Male',
            'street' => '1023 Klocko Camp, Suite 15', 'city' => 'Malden', 'state' => 'MA', 'postal_code' => '02148', 'country_code' => 'US',
            'phone_home' => '555-454-5317', 'phone_cell' => '555-000-1111', 'email' => 'ray@example.com', 'language' => 'Spanish',
            'race' => 'white|Asian', 'ethnicity' => 'hisp_or_latin', 'status' => 'married', 'pubpid' => 'MRN-77', 'ss' => '999-99-6377',
        ], $m->data);
        $this->assertSame(['an email address OpenEMR\'s validator refuses was left out'], $m->notes);
    }

    public function testAPatientWithoutAnOfficialNameOrAKnownSex(): void
    {
        $m = $this->mappers()->patient(['resourceType' => 'Patient', 'id' => 'p2', 'name' => [['given' => ['Ana'], 'family' => 'Ruiz']], 'gender' => 'unknown', 'birthDate' => '2001-05']);
        $this->assertSame(['Ana', 'Ruiz', 'Unknown', '2001-05-01'], [$m->data['fname'], $m->data['lname'], $m->data['sex'], $m->data['DOB']]);
        $this->assertStringContainsString('Unknown', $m->notes[0]);
        $this->assertSame('no first name, last name or birth date', $this->mappers()->patient(['resourceType' => 'Patient', 'id' => 'p3', 'name' => [['family' => 'Ruiz']], 'birthDate' => '2001-05-02'])->skip);
    }

    public function testAnEncounterAndItsProvider(): void
    {
        $export = FhirExport::fromResources([['resourceType' => 'Practitioner', 'id' => 'dr', 'identifier' => [['system' => Fhir::NPI, 'value' => '9999987198']]]]);
        $m = $this->mappers()->encounter([
            'resourceType' => 'Encounter', 'id' => 'e1', 'subject' => self::SUBJECT, 'class' => ['code' => 'AMB'],
            'type' => [['text' => 'Encounter for problem']], 'period' => ['start' => '2021-11-24T16:05:56-06:00', 'end' => '2021-11-24T16:20:56-06:00'],
            'participant' => [['individual' => ['reference' => 'Practitioner?identifier=' . Fhir::NPI . '|9999987198']]],
        ], $export);
        $this->assertSame(['date' => '2021-11-24 16:05:56', 'date_end' => '2021-11-24 16:20:56', 'class_code' => 'AMB', 'reason' => 'Encounter for problem',
            'pc_catid' => 5, 'facility_id' => 3, 'billing_facility' => 3, 'provider_id' => 7, 'sensitivity' => 'normal'], $m->data);
        $this->assertSame('p1', $m->patientId);

        $unknown = $this->mappers()->encounter(['resourceType' => 'Encounter', 'id' => 'e2', 'subject' => self::SUBJECT, 'class' => ['code' => 'XYZ'], 'period' => ['start' => '2021-01-01']], $export);
        $this->assertSame(['AMB', 0], [$unknown->data['class_code'], $unknown->data['provider_id']]);
        $this->assertCount(2, $unknown->notes, 'the class and the missing provider are both noted');
        $this->assertSame('no start date', $this->mappers()->encounter(['resourceType' => 'Encounter', 'id' => 'e3', 'subject' => self::SUBJECT], $export)->skip);
    }

    public function testConditionsAreDiagnosesWithTheirCodesAndStatus(): void
    {
        $export = FhirExport::fromResources([]);
        $m = $this->mappers()->chart(['resourceType' => 'Condition', 'id' => 'c1', 'subject' => self::SUBJECT, 'encounter' => ['reference' => 'Encounter/e1'],
            'clinicalStatus' => ['coding' => [['code' => 'resolved']]], 'verificationStatus' => ['coding' => [['code' => 'confirmed']]],
            'code' => ['coding' => [['system' => Fhir::SNOMED, 'code' => '44054006'], ['system' => Fhir::ICD10CM, 'code' => 'E11.9'], ['system' => 'urn:local', 'code' => 'x']], 'text' => 'Diabetes'],
            'onsetDateTime' => '2010-03-04T10:00:00-05:00', 'abatementDateTime' => '2020-01-02'], $export);
        $this->assertSame(['title' => 'Diabetes', 'begdate' => '2010-03-04', 'diagnosis' => 'SNOMED-CT:44054006;ICD10:E11.9', 'subtype' => 'diagnosis',
            'verification' => 'confirmed', 'enddate' => '2020-01-02', 'outcome' => '1'], $m->data);
        $this->assertSame('e1', $m->encounterId);
        $recorded = $this->mappers()->chart(['resourceType' => 'Condition', 'id' => 'c2', 'subject' => self::SUBJECT, 'code' => ['text' => 'Asthma'], 'recordedDate' => '2015-06-07'], $export);
        $this->assertSame('2015-06-07', $recorded->data['begdate'], 'the recorded date stands in for a missing onset');
        $this->assertSame('marked entered in error', $this->mappers()->chart(['resourceType' => 'Condition', 'id' => 'c3', 'subject' => self::SUBJECT, 'code' => ['text' => 'X'],
            'onsetDateTime' => '2015-01-01', 'verificationStatus' => ['coding' => [['code' => 'entered-in-error']]]], $export)->skip);
        $this->assertNotNull($this->mappers()->chart(['resourceType' => 'Condition', 'id' => 'c4', 'subject' => self::SUBJECT, 'code' => ['text' => 'X']], $export)->skip, 'no date at all');
    }

    public function testAllergiesTakeAListedReactionAndKeepTheRestInComments(): void
    {
        $m = $this->mappers()->chart(['resourceType' => 'AllergyIntolerance', 'id' => 'a1', 'patient' => self::SUBJECT,
            'code' => ['coding' => [['system' => Fhir::SNOMED, 'code' => '735029006']], 'text' => 'Shellfish'], 'recordedDate' => '1976-04-29T19:39:10-05:00',
            'reaction' => [['severity' => 'moderate', 'manifestation' => [['coding' => [['system' => Fhir::SNOMED, 'code' => '247472004']], 'text' => 'Hives'], ['text' => 'Swelling of lips']]]]], FhirExport::fromResources([]));
        $this->assertSame(['title' => 'Shellfish', 'diagnosis' => 'SNOMED-CT:735029006', 'verification' => '', 'begdate' => '1976-04-29',
            'reaction' => 'hives', 'severity_al' => 'moderate', 'comments' => 'Reaction: Swelling of lips'], $m->data);
    }

    public function testMedicationsByCodeOrReferenceAndStoppedOnesEnd(): void
    {
        $export = FhirExport::fromResources([['resourceType' => 'Medication', 'id' => 'm1', 'code' => ['coding' => [['system' => Fhir::RXNORM, 'code' => '314076']], 'text' => 'lisinopril 10 MG']]]);
        $active = $this->mappers()->chart(['resourceType' => 'MedicationRequest', 'id' => 'r1', 'subject' => self::SUBJECT, 'status' => 'active',
            'medicationReference' => ['reference' => 'Medication/m1'], 'authoredOn' => '2020-02-03T10:00:00Z'], $export);
        $this->assertSame(['title' => 'lisinopril 10 MG', 'begdate' => '2020-02-03', 'enddate' => null, 'diagnosis' => 'RXCUI:314076'], $active->data);
        $stopped = $this->mappers()->chart(['resourceType' => 'MedicationRequest', 'id' => 'r2', 'subject' => self::SUBJECT, 'status' => 'stopped',
            'medicationCodeableConcept' => ['text' => 'aspirin'], 'authoredOn' => '2019-01-01'], $export);
        $this->assertSame(['2019-01-01', '2019-01-01', ''], [$stopped->data['begdate'], $stopped->data['enddate'], $stopped->data['diagnosis']]);
        $this->assertStringContainsString('no end date', $stopped->notes[0]);
        $statement = $this->mappers()->chart(['resourceType' => 'MedicationStatement', 'id' => 's1', 'subject' => self::SUBJECT, 'status' => 'completed',
            'medicationCodeableConcept' => ['text' => 'amoxicillin'], 'effectivePeriod' => ['start' => '2018-03-01', 'end' => '2018-03-10']], $export);
        $this->assertSame(['2018-03-01', '2018-03-10'], [$statement->data['begdate'], $statement->data['enddate']]);
    }

    public function testImmunizationsNeedACvxCodeAndADate(): void
    {
        $export = FhirExport::fromResources([]);
        $m = $this->mappers()->chart(['resourceType' => 'Immunization', 'id' => 'i1', 'patient' => self::SUBJECT, 'status' => 'completed', 'primarySource' => true,
            'vaccineCode' => ['coding' => [['system' => Fhir::CVX, 'code' => '208']], 'text' => 'COVID-19 mRNA'], 'occurrenceDateTime' => '2021-11-03T17:05:56-05:00', 'lotNumber' => 'L1'], $export);
        $this->assertSame(['cvx_code' => '208', 'administered_date' => '2021-11-03 17:05:56', 'completion_status' => 'Completed', 'information_source' => 'new_immunization_record',
            'lot_number' => 'L1', 'expiration_date' => null, 'note' => 'COVID-19 mRNA'], $m->data);
        $notDone = $this->mappers()->chart(['resourceType' => 'Immunization', 'id' => 'i2', 'patient' => self::SUBJECT, 'status' => 'not-done', 'primarySource' => false,
            'vaccineCode' => ['coding' => [['system' => Fhir::CVX, 'code' => '141']]], 'occurrenceDateTime' => '2020-10-01'], $export);
        $this->assertSame(['Not_Administered', 'hist_inf_src_unspecified'], [$notDone->data['completion_status'], $notDone->data['information_source']]);
        $this->assertNotNull($this->mappers()->chart(['resourceType' => 'Immunization', 'id' => 'i3', 'patient' => self::SUBJECT, 'vaccineCode' => ['text' => 'flu'], 'occurrenceDateTime' => '2020-10-01'], $export)->skip);
    }

    public function testVitalSignsFoldIntoOneFormPerEncounterInOpenEmrsUnits(): void
    {
        $obs = static fn(string $id, string $loinc, float $value, string $unit, string $enc = 'e1', string $category = 'vital-signs'): array => [
            'resourceType' => 'Observation', 'id' => $id, 'status' => 'final', 'subject' => ['reference' => 'Patient/p1'], 'encounter' => ['reference' => "Encounter/$enc"],
            'category' => [['coding' => [['code' => $category]]]], 'code' => ['coding' => [['system' => Fhir::LOINC, 'code' => $loinc]]],
            'effectiveDateTime' => '2021-11-24T16:05:56-06:00', 'valueQuantity' => ['value' => $value, 'code' => $unit],
        ];
        $bp = ['resourceType' => 'Observation', 'id' => 'bp', 'status' => 'final', 'subject' => ['reference' => 'Patient/p1'], 'encounter' => ['reference' => 'Encounter/e1'],
            'category' => [['coding' => [['code' => 'vital-signs']]]], 'code' => ['coding' => [['system' => Fhir::LOINC, 'code' => '85354-9']]], 'effectiveDateTime' => '2021-11-24T16:06:56-06:00',
            'component' => [['code' => ['coding' => [['system' => Fhir::LOINC, 'code' => '8462-4']]], 'valueQuantity' => ['value' => 69, 'code' => 'mm[Hg]']],
                ['code' => ['coding' => [['system' => Fhir::LOINC, 'code' => '8480-6']]], 'valueQuantity' => ['value' => 121, 'code' => 'mm[Hg]']]]];
        $out = $this->mappers()->vitals([
            $obs('w', '29463-7', 70, 'kg'), $obs('h', '8302-2', 175, 'cm'), $obs('t', '8310-5', 37, 'Cel'), $obs('bmi', '39156-5', 22.86, 'kg/m2'), $bp,
            $obs('pain', '72514-3', 3, '{score}'), $obs('a1c', '4548-4', 6.1, '%', 'e1', 'laboratory'), $obs('w2', '29463-7', 71, 'kg', 'e2'),
            $obs('bmip', '59576-9', 71.5, '%', 'e2'), $obs('fio2', '3150-0', 28, '%', 'e2'),
        ], FhirExport::fromResources([]));

        $forms = array_values(array_filter($out, static fn(Mapped $m): bool => $m->skip === null));
        $skipped = array_column(array_map(static fn(Mapped $m): array => [$m->sourceId, $m->skip], array_filter($out, static fn(Mapped $m): bool => $m->skip !== null)), 1, 0);
        $this->assertSame(['pain' => 'a vital sign the Vitals form has no field for', 'a1c' => 'a lab result: not imported by this version'], $skipped);
        $this->assertCount(2, $forms, 'one Vitals form per encounter');
        $this->assertSame(['w', 'h', 't', 'bmi', 'bp'], $forms[0]->parts);
        $this->assertSame(['date' => '2021-11-24 16:06:56', 'weight' => 154.32, 'height' => 68.9, 'temperature' => 98.6, 'BMI' => 22.86, 'bpd' => 69.0, 'bps' => 121.0], $forms[0]->data);
        $this->assertSame('e1', $forms[0]->encounterId);
        $this->assertContains('weight converted from kg to lb', $forms[0]->notes);
        $this->assertSame(['date' => '2021-11-24 16:05:56', 'weight' => 156.53, 'ped_bmi' => 71.5, 'inhaled_oxygen_concentration' => 28.0], $forms[1]->data, 'BMI percentile and inhaled oxygen, in percent');
    }

    public function testSurveyObservationsBecomeAssessmentsOnTheObservationForm(): void
    {
        $survey = static fn(string $id, array $extra, ?string $enc = 'e1', ?string $loinc = '44261-6'): array => array_merge([
            'resourceType' => 'Observation', 'id' => $id, 'status' => 'final', 'subject' => ['reference' => 'Patient/p1'],
            'category' => [['coding' => [['code' => 'survey']]]], 'effectiveDateTime' => '2021-11-24T16:05:56-06:00',
            'code' => ['coding' => $loinc === null ? [] : [['system' => Fhir::LOINC, 'code' => $loinc, 'display' => 'PHQ-9 total score']]],
        ], $enc === null ? [] : ['encounter' => ['reference' => "Encounter/$enc"]], $extra);
        $out = $this->mappers()->vitals([
            $survey('phq9', ['valueQuantity' => ['value' => 16, 'unit' => '{score}', 'code' => '{score}']]),
            $survey('fall', ['valueCodeableConcept' => ['coding' => [['system' => Fhir::LOINC, 'code' => 'LA13038-7', 'display' => 'Low Risk']]]], 'e1', '59461-4'),
            $survey('prapare', ['component' => [
                ['code' => ['coding' => [['system' => Fhir::LOINC, 'code' => '63586-2', 'display' => 'Household income']]], 'valueQuantity' => ['value' => 23000, 'unit' => '/a']],
                ['code' => ['coding' => [['system' => Fhir::LOINC, 'code' => '93043-8']]], 'valueCodeableConcept' => ['coding' => [['system' => Fhir::LOINC, 'code' => 'LA30122-8', 'display' => 'I choose not to answer']]]],
                ['code' => ['text' => 'no code'], 'valueString' => 'x'],
            ]], 'e1', '93025-5'),
            $survey('noenc', ['valueQuantity' => ['value' => 3]], null),
            $survey('nocode', ['valueQuantity' => ['value' => 3]], 'e1', null),
            $survey('novalue', []),
            $survey('error', ['status' => 'entered-in-error', 'valueQuantity' => ['value' => 3]]),
        ], FhirExport::fromResources([]));
        $byId = [];
        foreach ($out as $m) {
            $byId[$m->sourceId] = $m;
        }
        $phq9 = $byId['phq9'];
        $this->assertSame([Mapped::ASSESSMENT, null, 'e1'], [$phq9->kind, $phq9->skip, $phq9->encounterId]);
        $this->assertSame(['ob_type' => 'assessment', 'category' => 'survey', 'date' => '2021-11-24 16:05:56', 'ob_status' => 'final', 'code' => 'LOINC:44261-6',
            'description' => 'PHQ-9 total score', 'ob_value' => '16', 'ob_unit' => '', 'sub_observations' => []], $phq9->data, 'a score: {score} is no unit');
        $this->assertSame(['LA13038-7', 'Low Risk'], [$byId['fall']->data['ob_value'], $byId['fall']->data['ob_value_code_description']]);
        $prapare = $byId['prapare']->data;
        $this->assertSame('', $prapare['ob_value'], 'the panel itself has no value');
        $this->assertSame(['LOINC:63586-2', '23000', '/a'], [$prapare['sub_observations'][0]['code'], $prapare['sub_observations'][0]['ob_value'], $prapare['sub_observations'][0]['ob_unit']]);
        $this->assertSame('LA30122-8', $prapare['sub_observations'][1]['ob_value']);
        $this->assertCount(2, $prapare['sub_observations'], 'a component with no code is left out');
        $this->assertSame(['a component with no LOINC code or no value left out'], $byId['prapare']->notes);
        $this->assertSame(['not tied to an encounter', 'a survey with no LOINC code', 'a survey with no value', 'marked entered in error or cancelled'],
            [$byId['noenc']->skip, $byId['nocode']->skip, $byId['novalue']->skip, $byId['error']->skip]);
    }

    public function testADocumentWithItsContentGoesToDocumentsAndOneByLinkIsSkipped(): void
    {
        $export = FhirExport::fromResources([['resourceType' => 'Patient', 'id' => 'p1']]);
        $note = "2012-11-05\n\n# Chief Complaint\nNo complaints.\n";
        $doc = static fn(array $over): array => $over + ['resourceType' => 'DocumentReference', 'id' => 'd1', 'status' => 'superseded', 'subject' => self::SUBJECT,
            'type' => ['coding' => [['system' => Fhir::LOINC, 'code' => '34117-2', 'display' => 'History and physical note']]],
            'date' => '2012-11-05T04:26:00.249-06:00',
            'content' => [['attachment' => ['contentType' => 'text/plain; charset=utf-8', 'data' => base64_encode($note)]]]];
        $m = $this->mappers()->document($doc([]), $export);
        $this->assertNull($m->skip);
        $this->assertSame(Mapped::DOCUMENT, $m->kind);
        $this->assertSame(['filename' => 'History-and-physical-note-2012-11-05.txt', 'mimetype' => 'text/plain', 'content' => $note, 'date' => '2012-11-05', 'category' => 'Medical Record'], $m->data);
        $this->assertSame(['marked superseded in the source; imported as a past document'], $m->notes);
        $this->assertSame('its content is a link (a Binary), not in the export',
            $this->mappers()->document($doc(['content' => [['attachment' => ['contentType' => 'application/pdf', 'url' => 'Binary/b1']]]]), $export)->skip);
        $this->assertSame('its content is not valid base64',
            $this->mappers()->document($doc(['content' => [['attachment' => ['contentType' => 'application/pdf', 'data' => '***']]]]), $export)->skip);
        $this->assertSame('marked entered in error', $this->mappers()->document($doc(['status' => 'entered-in-error']), $export)->skip);
        $pdf = $this->mappers()->document($doc(['type' => [], 'description' => 'Discharge summary', 'content' => [['attachment' => ['contentType' => 'application/pdf', 'data' => base64_encode('%PDF-1.4')]]]]), $export);
        $this->assertSame(['Discharge-summary-2012-11-05.pdf', 'application/pdf'], [$pdf->data['filename'], $pdf->data['mimetype']], 'named from the description when there is no type');
    }

    private function mappers(): Mappers
    {
        return new Mappers(new FakeLookups());
    }
}
