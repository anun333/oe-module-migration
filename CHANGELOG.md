# Changelog

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
