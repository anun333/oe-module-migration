# Changelog

## 0.2.3 (unreleased)

- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility:** the page declares its language (`<html lang>`).

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
