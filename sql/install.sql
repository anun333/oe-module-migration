--
-- Migration module tables.
--
-- migration_map records where each imported resource went, by its source system, type and
-- id, so a rerun of the same export, or a later one from the same system, adds nothing twice.
--

#IfNotTable migration_run
CREATE TABLE `migration_run` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `mode` VARCHAR(8) NOT NULL COMMENT 'dryrun or import',
    `source_system` VARCHAR(191) NOT NULL COMMENT 'the name given for the system the export came from',
    `source` TEXT NOT NULL COMMENT 'the folder read',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME DEFAULT NULL,
    `run_by` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'who ran it: the OpenEMR user given, or the system user',
    `report_json` MEDIUMTEXT COMMENT 'counts by resource type: read, imported or would be, skipped by reason',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Migration: each dry run or import';
#EndIf

#IfNotTable migration_map
CREATE TABLE `migration_map` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `source_system` VARCHAR(191) NOT NULL,
    `resource_type` VARCHAR(64) NOT NULL,
    `source_id` VARCHAR(64) NOT NULL,
    `target` VARCHAR(32) NOT NULL COMMENT 'patient, problem, allergy, medication, immunization, encounter or vitals',
    `target_id` BIGINT(20) NOT NULL COMMENT 'patient_data.pid, lists.id, immunizations.id, form_encounter.encounter or form_vitals.id',
    `pid` BIGINT(20) NOT NULL,
    `linked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when a source patient was linked to a patient already in OpenEMR, not created',
    `run_id` INT(11) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_source` (`source_system`, `resource_type`, `source_id`),
    KEY `idx_pid` (`pid`),
    KEY `idx_run` (`run_id`)
) ENGINE=InnoDB COMMENT='Migration: where each imported resource went';
#EndIf

#IfNotTable migration_issue
CREATE TABLE `migration_issue` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `run_id` INT(11) NOT NULL,
    `resource_type` VARCHAR(64) NOT NULL,
    `source_id` VARCHAR(64) NOT NULL,
    `level` VARCHAR(8) NOT NULL COMMENT 'skipped or failed',
    `message` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_run` (`run_id`)
) ENGINE=InnoDB COMMENT='Migration: what was skipped or failed, and why';
#EndIf
