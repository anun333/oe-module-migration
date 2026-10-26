# Migration for OpenEMR

Moves a practice's records into OpenEMR from another EHR. It reads the FHIR R4 export the old system can give, reports what will come across before anything is written, and then imports the core chart through OpenEMR's own services:
- patients, with their address, phone, language, race and ethnicity;
- problems, allergies and the medication list;
- immunizations;
- encounters, vital signs on the Vitals form, and scored questionnaires (PHQ-9, GAD-7, AUDIT-C, PRAPARE and others) on the Observation form, where OpenEMR's quality measures read them.

Running it twice adds nothing twice.

> **Status: 0.3.4, a pre-release. This module needs OpenEMR 8.1.0 or later; on an older release it stays switched off.** Tested on OpenEMR 8.4.1 and master with Synthea's synthetic patients only. Try it on a copy of your database first, and check the report and a sample of charts before importing for real.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it reads

The old system's FHIR export, in one folder:
- **Bulk NDJSON:** a FHIR Bulk Data `$export`, one resource per line and a file per type. Certified EHRs have offered it since the end of 2022, under ONC's standardized API criterion, §170.315(g)(10).
- **Bundle JSON files:** usually one per patient, as single-patient exports and Synthea give.

Anything else in the folder is ignored, as are the export's own Parameters and OperationOutcome files. Every resource is read, and references between them are resolved in the forms exports use: `Type/id`, `urn:uuid:`, and identifier searches such as a practitioner's NPI.

## How to run it

On the server, as the web server's user:

```
php interface/modules/custom_modules/oe-module-migration/bin/migrate.php \
    --source=/path/to/export --system="Old EHR" --user=admin
```

- **A dry run by default.** It writes nothing but its report: for each resource type, how many were read, how many would be imported, how many are skipped and why, and how many failed.
- **`--import`** writes.
- **`--system`** names the system the export came from. Each record imported is mapped to its source id under that name, so a rerun, or a later export from the same system, skips what's already in. A vital sign a later export adds to an encounter already imported goes onto that encounter's Vitals form; a reading of a vital sign the form already has (from the import, or entered on the form since) is left out, with an issue line, and the form keeps its value. The form is read and saved in the units OpenEMR stores (lb, in, °F), whatever the site shows.
- **`--user`** is the OpenEMR administrator the records are entered as. It must pass `admin/super`, the same check as the module's page.
- **`--link-duplicates`** handles possible duplicates: patients already in OpenEMR with the same first name, last name, birth date and sex. Without it they're reported and skipped; with it they're linked to the existing patient, and their records go on that chart.

**Admin › Migration** lists every run with its counts, and downloads what was skipped or failed.

## What it imports

| From | Into | How |
|---|---|---|
| Patient | Demographics, through `PatientService` | Name (official first), birth date, sex, home address, phones, email, marital status, language, race and ethnicity (US Core extensions, matched to OpenEMR's lists by their CDC codes), MRN as the patient ID when it isn't taken, SSN, date of death |
| Encounter | An encounter with its forms row, through `EncounterService` | Start and end, class, reason (or type), the provider when an OpenEMR user has the practitioner's NPI. Visit category Office Visit, the primary facility |
| Condition | The problem list, through `ConditionService` | Name, SNOMED CT and ICD-10 codes, onset (or recorded) date, end date, resolved, verification. Classified Diagnosis, so the quality measures read them |
| AllergyIntolerance | Allergies, through `AllergyIntoleranceService` | Substance and its codes, date, verification, the reaction where OpenEMR's reaction list has its SNOMED code (others go in the comments), severity |
| MedicationRequest, MedicationStatement | The medication list, through `ListService` | Name, RxNorm code, start date. A stopped or completed one gets an end date: its own, else its start date, so the list doesn't show it as current |
| Immunization | Immunizations, written as OpenEMR's Immunizations screen writes them | CVX code, date, completed or not given, lot, expiry, the encounter. On 8.4, `ImmunizationService::insert` is a stub, hence the direct write |
| DocumentReference | The patient's Documents, under Medical Record, through OpenEMR's `Document` class | Its content when it's in the export (base64 `attachment.data`), named from the document type and date, with its content type and date. One marked superseded is imported as a past document and noted; one that only links to a Binary is skipped with that reason |
| Vital-sign Observations | One Vitals form per encounter, through `VitalsService` | Blood pressure, weight, height, temperature, pulse, respiration, oxygen saturation, inhaled oxygen concentration, BMI, the BMI, weight-for-length and head circumference percentiles, head and waist circumference. Converted to the units OpenEMR keeps: pounds, inches and degrees Fahrenheit |
| Survey Observations | The encounter's Observation form, through `ObservationService`, as assessments | The LOINC code and name, the score or answer (a coded answer with its display), date and status. A panel such as PRAPARE keeps each answer as a sub-observation. Assessments are what OpenEMR's quality measures read |

Codes are written as OpenEMR writes them (`SNOMED-CT:`, `ICD10:`, `RXCUI:`). Anything that was adjusted on the way, such as a unit converted or an email address OpenEMR's validator refuses, is kept in the run's notes.

## What it doesn't import yet

- **Not imported:** lab results (about half of a typical export's Observations), social-history Observations (smoking and pregnancy status), procedures, diagnostic reports, care plans, claims and devices. Each is counted in the report with its reason.
- **USCDI v3**, the data set every certified EHR must be able to export from 2026: [docs/USCDI.md](docs/USCDI.md) lists all 94 elements and what Migration does with each.
- **Procedures and diagnostic reports wait for OpenEMR.** OpenEMR has no service that writes procedure orders, reports or results; its own C-CDA import writes them with SQL of its own. The report says so for each.
- **Practitioners, organizations and locations** are read to fill in other records, such as a provider's NPI, but aren't created.
- **Appointments, insurance and balances** aren't in FHIR US Core. They need per-vendor adapters.
- **Updating a patient already imported** from a newer export.

## Why not core's FHIR importer

OpenEMR 8.5 has `openemr:fhir-import` (openemr/openemr#14357), built for Synthea test data. It posts each resource to OpenEMR's FHIR write endpoints, which 8.4 lacks for most types. Its README lists what those endpoints can't take yet:
- lab, survey and social-history Observations;
- procedures and documents;
- the extensions that carry race and ethnicity.

This module writes through OpenEMR's own services instead, so it runs on 8.4 and keeps race and ethnicity.

## Who can open what

| What | Check | Same as |
|---|---|---|
| The runs page (Admin › Migration) | `admin/super` | the UDS module's settings |
| The command line | the `--user` named must pass `admin/super` | the page |

## Install

```
composer require anun333/oe-module-migration
```

Then in OpenEMR, Modules › Manage Modules: Register, Install and Enable **Migration**.

**Manual installs:** if you copy modules in by hand rather than through Composer, every module's own `vendor/` must hold the same version of openhealthcare-common. OpenEMR loads the library once, from the first module that registers it, so an older copy in one module is what every module gets.

## Compatibility

**PHP security.** PHP's php2shell advisory (2026-10-21; CVE-2026-109618 and CVE-2026-109619) is remote code execution without logging in, and it is being exploited. Run OpenEMR on PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, whatever the module needs. The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get that fix: they carry PHP 8.2.16 and 8.3.15 on Alpine releases that no longer get updates. Run 7.0 only on a patched PHP, or upgrade OpenEMR. OpenEMR rebuilt its 8.4.1, 8.3.0, 8.2.0 and 8.0.0.3 images with the fix on 2026-10-22 (forum t/27157); a container started before then needs pulling again.

**Older OpenEMR:** on 7.0.3 and older (no `OEGlobalsBag`), the module stays switched off rather than stop OpenEMR's pages; checked on fresh 7.0.2 and 7.0.3 installs with every module enabled. On fresh official 8.1.0, 8.3.0 and 8.5.0 images (2026-10-23) it installs through Module Manager and its pages that need no patient load, with every module enabled and with it alone (`dev/module_compat_check.sh`). 7.0.4 to 8.0 haven't been tried.

Tested with synthetic data on OpenEMR 8.4.1 (Docker image `openemr/openemr:8.4.1`) and master (8.5.0-dev, 1faefbbeb9, 2026-10-18), with `dev/migration_check.py` in the openhealthcare repository. The data was eight Synthea patients (seed 20261018), exported twice, as bulk NDJSON and as Bundles. The check:
- installs the module through Module Manager;
- confirms the dry run writes nothing and reads every line of every file;
- confirms the import brings in what the dry run said (1,723 records with Synthea v4.0.0: 8 patients, 270 encounters, 220 problems, 11 allergies, 80 medications, 112 immunizations, 270 documents, 485 vital signs on 82 Vitals forms, and 267 survey answers on Observation forms) and that OpenEMR's tables hold exactly that;
- confirms a rerun imports nothing, and that a later export with one more vital sign for an encounter already imported adds just that reading to the encounter's Vitals form (0.3.3), with the form's stored values unchanged on a site showing metric units too, and that a value entered on the form after the import is kept (0.3.4);
- imports the same people again, from the Bundles under another system name, and confirms every one is reported as a possible duplicate;
- compares one patient, a problem, an immunization, a converted weight, a PHQ-9 total (an assessment on its encounter's Observation form) and a PRAPARE panel (21 answers as sub-observations) with their source;
- loads the runs page;
- removes everything through the map.

It uses OpenEMR features present since 8.1.0. It installs and its pages load on 8.1.0 and 8.3.0 (above); the full end-to-end check runs on 8.4.1 and master. It needs PHP 8.2 or later.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.2, 8.3, 8.4 and 8.5.

## Other modules for OpenEMR

From the same project. Each installs with `composer require anun333/<module>` and is then enabled in Modules › Manage Modules.

| Module | What it does |
|---|---|
| [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms) | Printable clinic paperwork: consents, privacy notices, referral letters and validated screening questionnaires, with the clinic's own details, in English and Spanish |
| [UDS Report](https://github.com/anun333/oe-module-uds-report) | HRSA's Uniform Data System report for health centers: the patient, visit and diagnosis tables, eCQM readiness, a Table 9D worksheet and grant evidence |
| [Staff Worklist](https://github.com/anun333/oe-module-staff-worklist) | One queue of outstanding staff work: unsigned results, orders with no result, referrals with no reply, screening and claims follow-up |
| [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) | HRSA's sliding fee discount program: the schedule checked against Chapter 9, income assessments, and the discount applied |
| [Care Management](https://github.com/anun333/oe-module-care-management) | CCM, PCM and BHI enrollment with CMS's consent elements, monthly time logging, and the codes each month meets |
| [Layout Export](https://github.com/anun333/oe-module-layout-export) | Layouts and lists exported to files you can keep in version control, compared with the site or another file; reads the old Backup page's SQL files without running them |

## Codes and their terms

The module reads LOINC codes to place what it imports: the vital signs it folds into a Vitals form and the survey Observations it saves as assessments (`src/Map/Mappers.php`, `src/Map/Fhir.php`). LOINC's notice, as its licence asks of LOINC content passed on:

> This material contains content from LOINC (http://loinc.org). LOINC is copyright © Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
