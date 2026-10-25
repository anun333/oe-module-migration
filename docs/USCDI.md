# USCDI v3: what Migration imports

USCDI v3 is the data set every certified EHR must be able to exchange from January 1, 2026 (ONC's HTI-1 rule, 45 CFR 170.213(b)). An EHR you are leaving can export these data classes as FHIR US Core, and this page says what Migration does with each element. It covers the 19 data classes and 94 elements of [USCDI Version 3, October 2022 Errata](https://www.healthit.gov/isa/sites/isa/files/2022-10/USCDI-Version-3-October-2022-Errata-Final.pdf), with ONC's names. The page was written for Migration 0.3.0.

- **Imported:** written into OpenEMR, through OpenEMR's own services.
- **Partly:** some of it, as the note says.
- **Not yet:** read, counted and reported as skipped, with the reason.

Most of the "not yet" rows wait on OpenEMR: it has no service that writes lab and procedure orders, reports or results, so Migration doesn't write them with SQL of its own.

| Data class | Element | Status | Where it goes, or why not |
|---|---|---|---|
| Allergies and Intolerances | Substance (Medication) | Imported | Allergies, with the code |
| | Substance (Drug Class) | Imported | Allergies, with the code |
| | Reaction | Imported | A reaction OpenEMR's list has; any other in the allergy's comments |
| Assessment and Plan of Treatment | Assessment and Plan of Treatment | Not yet | CarePlan resources aren't imported |
| | SDOH Assessment | Partly | When sent as survey Observations (PRAPARE, AHC HRSN and other scored screens): assessments on the encounter's Observation form, with a panel's answers as sub-observations |
| Care Team Member(s) | Care Team Member Name | Partly | A practitioner matched by NPI to an OpenEMR user becomes the encounter's provider; CareTeam resources aren't imported |
| | Care Team Member Identifier | Partly | The NPI, used for that match |
| | Care Team Member Role | Not yet | |
| | Care Team Member Location | Not yet | |
| | Care Team Member Telecom | Not yet | |
| Clinical Notes | Consultation Note | Imported | Documents, under Medical Record, when the DocumentReference carries its content; one that only links to a Binary is skipped |
| | Discharge Summary Note | Imported | As above |
| | History & Physical | Imported | As above |
| | Procedure Note | Imported | As above |
| | Progress Note | Imported | As above |
| Clinical Tests | Clinical Test | Not yet | No OpenEMR service writes procedure orders and results |
| | Clinical Test Result/Report | Not yet | As above |
| Diagnostic Imaging | Diagnostic Imaging Test | Not yet | As above |
| | Diagnostic Imaging Report | Not yet | As above; a report sent as a DocumentReference with its content is filed in Documents |
| Encounter Information | Encounter Type | Partly | The class is kept, and the type is the reason when the source gives none; the visit category is the site's default |
| | Encounter Diagnosis | Not yet | Diagnoses are imported as problems, not linked to the encounter |
| | Encounter Time | Imported | Start and end |
| | Encounter Location | Partly | Every encounter goes to the facility chosen for the run |
| | Encounter Disposition | Not yet | |
| Goals | Patient Goals | Not yet | |
| | SDOH Goals | Not yet | |
| Health Insurance Information | Coverage Status | Not yet | Coverage resources aren't imported yet |
| | Coverage Type | Not yet | |
| | Relationship to Subscriber | Not yet | |
| | Member Identifier | Not yet | |
| | Subscriber Identifier | Not yet | |
| | Group Identifier | Not yet | |
| | Payer Identifier | Not yet | |
| Health Status/Assessments | Health Concerns | Imported | Conditions are imported as problems, whatever their category |
| | Functional Status | Partly | When sent as survey Observations: assessments on the Observation form |
| | Disability Status | Partly | As above |
| | Mental/Cognitive Status | Partly | As above |
| | Pregnancy Status | Not yet | Social-history Observations aren't imported |
| | Smoking Status | Not yet | As above |
| Immunizations | Immunizations | Imported | By CVX code, with the date given |
| Laboratory | Tests | Not yet | No OpenEMR service writes lab orders and results |
| | Values/Results | Not yet | As above |
| | Specimen Type | Not yet | As above |
| | Result Status | Not yet | As above |
| Medications | Medications | Imported | The medication list, by RxNorm code; a stopped one with its end date |
| | Dose | Not yet | The list keeps the medicine, not its dosage |
| | Dose Units of Measure | Not yet | As above |
| | Indication | Not yet | |
| | Fill Status | Not yet | MedicationDispense isn't imported |
| Patient Demographics/Information | First Name | Imported | From the official name |
| | Last Name | Imported | |
| | Middle Name (Including middle initial) | Imported | The further given names |
| | Name Suffix | Not yet | A prefix is kept as the title; a suffix isn't |
| | Previous Name | Not yet | |
| | Date of Birth | Imported | |
| | Date of Death | Imported | When dated; "deceased" with no date is noted and left out |
| | Race | Imported | By the CDC codes OpenEMR's race list keeps |
| | Ethnicity | Imported | By the CDC codes OpenEMR's ethnicity list keeps |
| | Tribal Affiliation | Not yet | |
| | Sex | Imported | Male, Female or Unknown |
| | Sexual Orientation | Not yet | |
| | Gender Identity | Not yet | |
| | Preferred Language | Imported | By ISO code, where OpenEMR's language list has it |
| | Current Address | Imported | The home address |
| | Previous Address | Not yet | |
| | Phone Number | Imported | |
| | Phone Number Type | Imported | Home, mobile and work, into OpenEMR's three phone fields |
| | Email Address | Imported | |
| | Related Person's Name | Not yet | |
| | Related Person's Relationship | Not yet | |
| | Occupation | Not yet | |
| | Occupation Industry | Not yet | |
| Problems | Problems | Imported | Problems, classified Diagnosis so the quality measures read them |
| | SDOH Problems/Health Concerns | Imported | As problems, when sent as Conditions |
| | Date of Diagnosis | Imported | The onset date |
| | Date of Resolution | Imported | The abatement date |
| Procedures | Procedures | Not yet | No OpenEMR service writes procedures; each is reported as waiting for one |
| | SDOH Interventions | Not yet | As above |
| | Reason for Referral | Not yet | ServiceRequest isn't imported |
| Provenance | Author Time Stamp | Not yet | Provenance resources aren't read; each record keeps its own date |
| | Author Organization | Not yet | |
| Unique Device Identifier(s) for a Patient's Implantable Device(s) | Unique Device Identifier(s) for a Patient's Implantable Device(s) | Not yet | Device resources aren't imported |
| Vital Signs | Systolic Blood Pressure | Imported | The Vitals form of the encounter |
| | Diastolic Blood Pressure | Imported | As above |
| | Heart Rate | Imported | As above |
| | Respiratory Rate | Imported | As above |
| | Body Temperature | Imported | In degrees Fahrenheit, as OpenEMR keeps it |
| | Body Height | Imported | In inches |
| | Body Weight | Imported | In pounds |
| | Pulse Oximetry | Imported | |
| | Inhaled Oxygen Concentration | Imported | (0.3.0) |
| | BMI Percentile (2 - 20 years) | Imported | (0.3.0) |
| | Weight-for-length Percentile (Birth - 24 Months) | Imported | (0.3.0) |
| | Head Occipital-frontal Circumference Percentile (Birth - 36 Months) | Imported | (0.3.0) |

**In all:** 41 imported, 8 partly, 45 not yet.

ONC's March 2025 notice lets certified products leave out Sexual Orientation and Gender Identity, and record Sex only as female or male. Its USCDI v3.1 removes or updates those elements, but 45 CFR 170.213 still names v3.
