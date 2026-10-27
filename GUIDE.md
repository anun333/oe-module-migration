# Migration: staff guide

For the person moving a clinic's records into OpenEMR from another EHR, usually an administrator working with whoever runs the server. What it reads and imports, and how to install it, are in the [README](README.md).

The module reads the old system's FHIR export and enters the records through OpenEMR's own services. Runs start on the server's command line; the page in OpenEMR lists them. It has been tested only on made-up patients, not yet on a real migration.

## The administrator moving records in

### Get the export from the old system

Ask the old vendor for a FHIR export: the bulk export certified EHRs have offered since the end of 2022 (a folder of NDJSON files, one per resource type), or one Bundle file per patient. Put the files in one folder on the OpenEMR server. Anything else in that folder is ignored.

### Do a dry run first

*Where:* the server's command line, as the web server's user. The command is on the module's page and in the README.

1. Run it without `--import`. A dry run writes nothing but its report.
2. Name the old system with `--system` ("Old EHR", say), and keep using that name for every later run from it.
3. Open Admin › **Migration**. The newest run is shown; pick another in **Run**.
4. Read the table, one row per **Resource**: how many were **Read**, how many it **Would import**, how many are **Already imported**, how many it **Skipped** and how many **Failed**, with **Why skipped**.
5. Choose **Download what was skipped or failed** to go through them line by line.

Fix what you can in the export or in OpenEMR (an OpenEMR user with the practitioner's NPI, say, so encounters get their provider), and run the dry run again.

### Import

1. Run the same command with `--import`.
2. If OpenEMR already has patients with the same first name, last name, birth date and sex, they are reported and skipped. Add `--link-duplicates` only once you've checked them: it puts the imported records on the existing chart.
3. Read the run on Admin › **Migration**: the table now says **Imported**. Open a few charts and compare them with the old system.

*What it doesn't do:*
- Lab results, smoking and pregnancy status, procedures, diagnostic reports, care plans, claims and devices aren't imported yet; the report counts each with its reason.
- Appointments, insurance and balances aren't in a FHIR export, so they need another route.
- It doesn't update a patient already imported from a newer export.

### Run again from a later export

Run with the same `--system` name. Records already imported from that system are skipped and counted as **Already imported**, never entered twice. A vital sign a later export adds to an encounter already imported goes onto that encounter's Vitals form; a reading the form already has keeps its value, with a note.

## Who can use it

The page and the command line need an administrator (`admin/super` in OpenEMR's access control): the command's `--user` must be one. Each record is entered as that user.
