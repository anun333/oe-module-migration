<?php

/**
 * Migration: each dry run and import, with its counts by resource type and what was
 * skipped or failed. The runs themselves are started from the command line
 * (bin/migrate.php), since an export can be large. ?run=N&format=csv downloads a run's issues.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\Migration\Run\Report;
use OpenHealthcare\Migration\Store\MapStore;

// Importing a practice's records is an administrator's job: the same check as the UDS module's settings.
if (!AclMain::aclCheckCore('admin', 'super')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for admin/super: Migration', xl('Migration'));
}
$store = new MapStore();
$runs = $store->runs();
$selected = (int)($_GET['run'] ?? ($runs[0]['id'] ?? 0));
$run = null;
foreach ($runs as $r) {
    if ($r['id'] === $selected) {
        $run = $r;
    }
}
if ($run !== null && ($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="migration-run-' . $run['id'] . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, ['Resource', 'Source id', 'Outcome', 'Why'], ',', '"', '');
    foreach ($store->issues($run['id']) as $i) {
        fputcsv($out, [$i['type'], $i['sourceId'], $i['level'], $i['message']], ',', '"', '');
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<?php // The page is in the signed-in user's OpenEMR language, so translated text is announced in it. ?>
<html lang="<?php echo attr(OpenEmr::pageLang()); ?>" dir="<?php echo attr(OpenEmr::pageDir()); ?>">
<head>
    <title><?php echo xlt('Migration'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\Migration\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Migration from another EHR'); ?></h1>
    <p class="small text-muted"><?php echo xlt('Reads a FHIR R4 export (the bulk NDJSON files certified EHRs offer, or Bundle files) and imports patients, problems, allergies, medications, immunizations, encounters and vital signs through OpenEMR. Runs are started on the server, as the web user; a dry run writes nothing. Records already imported from the same system are never imported twice.'); ?></p>
    <pre class="small bg-light p-2">php interface/modules/custom_modules/oe-module-migration/bin/migrate.php --source=/path/to/export --system="Old EHR" --user=admin [--import]</pre>

    <?php if ($runs === []) { ?>
        <p><?php echo xlt('No runs yet.'); ?></p>
    <?php } else { ?>
        <form method="get" class="form-inline mb-3">
            <label class="mr-2" for="run"><?php echo xlt('Run'); ?></label>
            <select class="form-control form-control-sm mr-2" id="run" name="run" onchange="this.form.submit()">
                <?php foreach ($runs as $r) { ?>
                    <option value="<?php echo attr((string)$r['id']); ?>" <?php echo $r['id'] === $selected ? 'selected' : ''; ?>>
                        <?php echo text('#' . $r['id'] . ' ' . ($r['mode'] === 'import' ? xl('Import') : xl('Dry run')) . ', ' . $r['system'] . ', ' . $r['started']); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary" href="?run=<?php echo attr_url((string)$selected); ?>&format=csv"><?php echo xlt('Download what was skipped or failed'); ?></a>
        </form>
        <?php if ($run !== null) { ?>
            <p class="small"><?php echo text(sprintf(xl('%1$s of %2$s, run by %3$s, %4$s to %5$s.'), $run['mode'] === 'import' ? xl('Import') : xl('Dry run'), $run['source'], $run['runBy'], $run['started'], $run['finished'] ?: xl('not finished'))); ?></p>
            <table class="table table-sm w-auto">
                <thead><tr><th><?php echo xlt('Resource'); ?></th><th class="text-right"><?php echo xlt('Read'); ?></th>
                    <th class="text-right"><?php echo $run['mode'] === 'import' ? xlt('Imported') : xlt('Would import'); ?></th>
                    <th class="text-right"><?php echo xlt('Already imported'); ?></th><th class="text-right"><?php echo xlt('Skipped'); ?></th>
                    <th class="text-right"><?php echo xlt('Failed'); ?></th><th><?php echo xlt('Why skipped'); ?></th></tr></thead>
                <tbody>
                <?php foreach (Report::rows(is_array($run['report']['counts'] ?? null) ? $run['report']['counts'] : []) as $row) { ?>
                    <tr data-type="<?php echo attr($row['type']); ?>">
                        <td><?php echo text($row['type']); ?></td>
                        <td class="text-right"><?php echo text((string)$row['read']); ?></td>
                        <td class="text-right"><?php echo text((string)$row['imported']); ?></td>
                        <td class="text-right"><?php echo text((string)$row['already']); ?></td>
                        <td class="text-right"><?php echo text((string)$row['skipped']); ?></td>
                        <td class="text-right"><?php echo text((string)$row['failed']); ?></td>
                        <td class="small"><?php echo text($row['reasons']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php foreach (is_array($run['report']['problems'] ?? null) ? $run['report']['problems'] : [] as $p) { ?>
                <p class="small text-danger"><?php echo xlt('Not read'); ?>: <?php echo text((string)$p); ?></p>
            <?php } ?>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>
