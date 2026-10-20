# Changelog

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
