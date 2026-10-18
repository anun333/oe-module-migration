<?php

/**
 * Reads a FHIR R4 export into OpenEMR from the command line, as the web server's user.
 * A dry run by default: it reports what would be imported and what wouldn't, and writes
 * nothing but the report. --import writes. A rerun adds nothing already imported from
 * the same --system.
 *
 *   php interface/modules/custom_modules/oe-module-migration/bin/migrate.php \
 *       --source=/path/to/export --system="Old EHR" --user=admin [--import] [--link-duplicates] [--site=default]
 *
 * --user names the OpenEMR administrator the records are entered as; it must pass the
 * same check as the module's page (admin/super).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opts = getopt('', ['source:', 'system:', 'user:', 'import', 'link-duplicates', 'site:']);
$source = is_string($opts['source'] ?? null) ? $opts['source'] : '';
$system = trim(is_string($opts['system'] ?? null) ? $opts['system'] : '');
$username = is_string($opts['user'] ?? null) ? $opts['user'] : '';
if ($source === '' || $system === '' || $username === '') {
    fwrite(STDERR, "usage: php migrate.php --source=DIR --system=NAME --user=USERNAME [--import] [--link-duplicates] [--site=default]\n");
    exit(2);
}
$ignoreAuth = true;
$sessionAllowWrite = true;
$_GET['site'] = is_string($opts['site'] ?? null) ? $opts['site'] : 'default';
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenHealthcare\Migration\Map\Mappers;
use OpenHealthcare\Migration\Run\Migrator;
use OpenHealthcare\Migration\Run\OpenEmrWriter;
use OpenHealthcare\Migration\Run\Report;
use OpenHealthcare\Migration\Source\FhirExport;
use OpenHealthcare\Migration\Store\MapStore;

$user = QueryUtils::fetchRecords("SELECT `id`, `username`, `facility_id` FROM `users` WHERE `username` = ? AND `active` = 1", [$username])[0] ?? null;
if ($user === null || !AclMain::aclCheckCore('admin', 'super', $username)) {
    fwrite(STDERR, "--user must be an active OpenEMR user who passes admin/super, the module's own check\n");
    exit(2);
}
// Records are entered as this user, as they would be through the screens.
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$session->set('authUser', $user['username']);
$session->set('authUserID', (int)$user['id']);
$session->set('authProvider', 'Default');

try {
    $export = FhirExport::fromDirectory($source);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$dry = !isset($opts['import']);
$store = new MapStore();
$runId = $store->startRun($dry ? 'dryrun' : 'import', $system, realpath($source) ?: $source, $username);
$migrator = new Migrator(
    new Mappers(new \OpenHealthcare\Migration\Run\OpenEmrLookups()),
    new OpenEmrWriter((int)$user['id'], (int)($user['facility_id'] ?? 0)),
    $store->mapped($system),
    $dry,
    isset($opts['link-duplicates']),
    static fn(string $type, string $id, string $target, int $targetId, int $pid, bool $linked) => $store->map($system, $type, $id, $target, $targetId, $pid, $runId, $linked),
);
$result = $migrator->run($export);
foreach ($result['issues'] as $issue) {
    $store->issue($runId, $issue['type'], $issue['id'], $issue['level'], $issue['message']);
}
$report = ['counts' => $result['report'], 'problems' => $export->problems()];
$store->finishRun($runId, $report);

echo ($dry ? 'Dry run' : 'Import') . " $runId of " . ($source) . " as \"$system\"" . ($dry ? ': nothing was written. Run again with --import to write.' : '') . "\n";
echo Report::text($result['report'], $dry);
foreach ($export->problems() as $problem) {
    echo "  not read: $problem\n";
}
exit(array_sum(array_column($result['report'], 'failed')) > 0 ? 1 : 0);
