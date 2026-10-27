# Changelog

## 0.3.6 (2026-10-26)

- **Picking a run no longer reloads the page.** The **Run** picker reloaded the page as soon as it changed, so moving through it with the keyboard or a screen reader jumped to the first run passed (WCAG 3.2.2). A **Show** button now shows the run picked, as UDS 0.17.5's period picker does.
- **A staff guide**, GUIDE.md (export, dry run, import, run again), opened by **Help** on the page at the installed version's tag, with a test that every label it quotes is on the page.
- **Install note:** restart the container after adding, upgrading or removing a module copied in by hand (OpenEMR's official 8.0.0 to 8.5.0 images keep class locations, and classes not found, in APCu).

## 0.3.5 (2026-10-26)

- **Pages are announced in your language.** Each page now declares the signed-in user's OpenEMR language and its direction instead of English, so a screen reader reads the translated text in that language. It comes from openhealthcare-common 1.4.0, which this version requires. If another module's older copy of the library loads first on a site that installs by hand, the pages say English, as before, rather than stop.
- **Manual installs:** the README now says that every module's own copy of openhealthcare-common must be the same version, since OpenEMR loads the library once, from the first module that registers it.
- **LOINC's notice:** the README now carries the notice LOINC's licence asks for, since the module reads LOINC codes to place vital signs and survey Observations.

## 0.3.4 (2026-10-26)

- **Fixed: on a site that shows metric vital signs, 0.3.3 rewrote an imported Vitals form's stored values when a later export added a reading to it.** OpenEMR stores weight in lb, height and the circumferences in inches and temperature in °F, and on a site whose Units for Visit Forms are metric (Administration › Config › Locale: "Show both US and metric (main unit is metric)" or "Show metric only", values 2 and 4) its VitalsService reads those back converted to kg, cm and °C. 0.3.3 saved what it read, so the form's weight, height, temperature, head and waist circumference were stored again as the metric numbers in lb, in and °F columns: a weight of 164.91 lb became 74.80, and 33.93 after a second added reading. Now the form is read and saved in the units it's stored in, as the first import writes it, and nothing else on the form changes. Sites showing US units (1 or 3) weren't affected.
  - **If you ran 0.3.3 on a metric site** and imported a later export that added readings to encounters already imported: the import's report lists the readings added (`imported` under Observation, mapped to an existing Vitals form). On each of those forms, check weight, height, temperature and the circumferences against the source; a value about 2.2 times too small (weight) or 2.54 times too large (height) was converted. Re-enter it on OpenEMR's Vitals screen.
- **Fixed: a later reading could overwrite a value on the form.** Whether the form already has a vital sign was judged by the export's own readings, so a value someone entered on the form after the import, or one from an earlier export that a later export no longer carries, was replaced by the later reading. Now any value the stored form holds counts, and the later reading is left out with an issue line ("the form's value kept"), as for a reading the export already brought in. A field holding nothing (NULL or 0) is filled.
- **Checked:** three new unit tests (a value entered on the form is kept; a reading the later export no longer carries is kept; a blank field is filled). The first two fail on 0.3.3's code; all 29 pass on PHP 8.2 and 8.5. End to end on OpenEMR 8.4.1 (`dev/migration_check.py`, extended): later readings added at Units for Visit Forms 2 and 4 leave the form's weight, height, temperature and the rest as stored, and a respiration entered on the form after the import (20) is kept while the later reading (18) is skipped. On 0.3.3's code the same run stored 74.80 for a weight of 164.91 lb, then 33.93, overwrote the 20 with 18, and four checks failed.

## 0.3.3 (2026-10-26)

- **Fixed: a later export from the same system wrote an encounter's vital signs a second time.** Vital signs fold into one Vitals form per encounter, and the form counted as imported only when every reading in it was. So an export that added one reading to an encounter already imported wrote a second Vitals form with all of them, and the map's unique key then stopped the run part-way, leaving that form unmapped. Now the new reading goes onto the Vitals form already there, the way OpenEMR's own Vitals screen saves an edit: the form's other values and its uuid stay as they were, and the encounter keeps one Vitals form. A later reading of a vital sign the form already has (a second weight, say) is left out with an issue line, and the form keeps its value.
- **Fixed: a record that failed part-way could be written twice.** Each record is now written in one transaction with its map entry (`QueryUtils::inTransaction`, OpenEMR 8.1.0 and later), so a failure part-way through, such as a medication's second statement or its map entry, leaves nothing behind, the record is reported as failed, and the run goes on. A document's file is the exception: it's on disk before its row, and stays if the row fails.
- **PHP 8.2 or later**, as OpenEMR 8.1.0 allows: the module asked for 8.3 but uses nothing newer than 8.2. CI runs the tests on 8.2 too.
- **Checked:** unit tests, four of them new (a later export adds a reading to the form already imported; a reading the form already has is left out; a record that fails part-way leaves nothing; a map entry that fails rolls its record back and the run goes on). The new tests fail on 0.3.2's code, and breaking each fix on purpose fails its test; all 26 pass on PHP 8.2, 8.4 and 8.5. End to end on OpenEMR 8.4.1 (`dev/migration_check.py`, extended): a later export with one more oxygen saturation reading imports just that reading onto the encounter's Vitals form, the other values and the uuid unchanged, still one form, and a rerun adds nothing. On 0.3.2's code the same export wrote a second Vitals form and the run stopped on the map's unique key.

## 0.3.2 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.3.1 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without the getters its bootstrap called, which came with 8.1.0. The module needs 8.1.0 or later (its pages use 8.1's session and service container), so on an older release it now stays switched off, as it already did before 7.0.4. Its pages ask openhealthcare-common 1.3.3, now required at runtime, for the web root and OpenEMR's folders. Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.

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
