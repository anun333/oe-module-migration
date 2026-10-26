# Changelog

## 0.3.1 (unreleased)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without the getters its bootstrap called, which came with 8.1.0. The module needs 8.1.0 or later (its pages use 8.1's session and service container), so on an older release it now stays switched off, as it already did before 7.0.4. Its pages ask openhealthcare-common 1.3.2, now required at runtime, for the web root and OpenEMR's folders. Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.

## 0.3.0 (2026-10-25)

**Survey answers where OpenEMR's quality measures read them, and the rest of USCDI v3's vital signs.**

- **Survey Observations** (PHQ-9, PHQ-2, GAD-7, AUDIT-C, DAST-10, HARK, PRAPARE, the PEG and other scored questionnaires) are imported onto the encounter's Observation form, through OpenEMR's own `ObservationService::saveObservation`, as assessments. Assessments are what core's quality measures read (`Qdm\Services\AssessmentService`).
  - Each keeps its LOINC code and name, its score or answer, its date and its status. A coded answer keeps its display (`ob_value_code_description`, where the column exists).
  - A panel such as PRAPARE becomes one observation with each answer as a sub-observation. An answer with no LOINC code or no value is left out and noted.
  - One with no LOINC code, no encounter, no date or no value is skipped with that reason, as is one entered in error.
- **Vital signs:** inhaled oxygen concentration and the BMI, weight-for-length and head circumference percentiles now go onto the Vitals form, which has fields for them. With these, Migration covers every USCDI v3 vital sign.
- **[docs/USCDI.md](docs/USCDI.md):** all 94 USCDI v3 elements (October 2022 errata) and what Migration does with each: 41 imported, 8 partly, 45 not yet, with the reason for each.
- **Social-history Observations** (smoking and pregnancy status) are still skipped, with that reason.
- **Checked:** unit tests for surveys (a score, a coded answer, a panel's components, and the five reasons to skip) and for the new vital signs. End to end on OpenEMR 8.4.1 and master, 22 checks each: 1,723 records, including 267 survey answers. A PHQ-9 total is compared with its source, and a PRAPARE panel keeps its 21 answers.

## 0.2.3 (2026-10-23)

- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility:** the page declares its language (`<html lang>`).
- **README:** run OpenEMR on a PHP with PHP's php2shell fix (2026-10-21: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later). The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get it.
- **FOOTPRINT.md** now comes from openhealthcare-common's footprint tool (1.1), the one every module shares; `tools/footprint.php` only loads it. The sheet's closing notes, what it can't show, are this module's own (in composer.json): the old ones were the Staff Worklist's, and said this module's permission checks had been verified end to end with restricted users, which they haven't. It also lists the documents it writes through OpenEMR's Document class.
- **Checked:** unit tests; the nightly matrix on 8.4.1 and master (20 checks each, on pinned synthetic FHIR exports); fresh official 8.1.0, 8.3.0 and 8.5.0 images, with every module enabled and with it alone (on 7.0.2 and 7.0.3 it stays switched off, as designed).

## 0.2.2 (2026-10-22)

- **No longer stops an older OpenEMR.** OpenEMR loads a module's bootstrap on every request, the login page included. This module's used `OEGlobalsBag`, which came with OpenEMR 7.0.4 and 8.0, so on 7.0.3 or older, enabling it made every page fail (HTTP 500), login included, until the module was switched off in the database. It now stays switched off there instead. Found by the nightly matrix's new fresh 7.0.2 install (`dev/module_compat_check.sh`).

## 0.2.1 (2026-10-22)

- **The footprint tool refuses web requests.** Composer installs the package under the web root, so `tools/footprint.php` now answers a web request with 404 and runs only from the command line, as Clinic Forms' tools do.
- **A test that no translated string has an apostrophe or quote mark**, since OpenEMR's `xl()` shows them as backticks. None did.
- **README:** Layout Export is listed among the project's other modules.

## 0.2.0 (2026-10-19)

- **Documents.** A DocumentReference whose content is in the export (base64 `attachment.data`, as Synthea and many single-patient exports give) is filed in the patient's Documents under Medical Record, through OpenEMR's own `Document` class.
  - It's named from the document type and date, with its content type and the source's date.
  - One marked superseded is imported as a past document, and the run notes it. One marked entered in error is skipped.
  - One that only links to a Binary is skipped with that reason, as is content that isn't valid base64.
- **Procedures and diagnostic reports** are reported as waiting for OpenEMR. It has no service that writes procedure orders, reports or results; its C-CDA import writes them with SQL of its own. The report used to say only "not imported by this version".
- **Tested:**
  - unit tests for documents: decoded content, file name, content type, superseded, a link to a Binary, bad base64, entered in error;
  - on OpenEMR 8.4.1 with Synthea's export: all 173 notes filed, one compared with its source by name, type, date and size, and the 520 procedures reported with the reason.

## 0.1.0 (2026-10-18)

First release, as a standalone Composer package.

- **Input:** reads a FHIR R4 export (bulk NDJSON or Bundles) and resolves references between resources in the forms exports use.
- **Dry run by default:** counts by resource type, what would be imported, and what is skipped and why.
- **`--import`:** brings in through OpenEMR's own services:
  - patients;
  - encounters with their forms rows;
  - problems, classified Diagnosis;
  - allergies and the medication list;
  - immunizations, written as OpenEMR's Immunizations screen writes them;
  - vital signs, one Vitals form per encounter in OpenEMR's units.
- **Matching and reruns:** a map table makes reruns add nothing. Patients matching one already in OpenEMR by name, birth date and sex are reported, or linked with `--link-duplicates`.
- **Admin › Migration:** each run's counts, and a CSV of what was skipped or failed.
- **Tested:**
  - **Isolated tests:** 16; three deliberate breaks each fail them.
  - **On OpenEMR 8.4.1 and master:** `dev/migration_check.py`, 18 checks, with eight Synthea patients as NDJSON and Bundles.
