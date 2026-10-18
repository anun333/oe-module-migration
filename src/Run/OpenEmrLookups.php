<?php

/**
 * OpenEMR's lists and users, read once each. Race and ethnicity options keep their CDC
 * code in the option's notes, languages their ISO 639-1 code; reactions keep their
 * SNOMED code in codes ("SNOMED-CT:...").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Run;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Migration\Map\Lookups;

final class OpenEmrLookups implements Lookups
{
    /** @var array<string, array<string, string>> list => code => option id */
    private array $cache = [];

    private ?int $category = null;
    private ?int $facility = null;

    public function race(string $cdcCode): ?string
    {
        return $this->byNotes('race', $cdcCode);
    }

    public function ethnicity(string $cdcCode): ?string
    {
        return $this->byNotes('ethnicity', $cdcCode);
    }

    public function language(string $iso): ?string
    {
        return $this->byNotes('language', $iso);
    }

    public function reaction(string $snomed): ?string
    {
        if (!isset($this->cache['reaction'])) {
            $this->cache['reaction'] = [];
            foreach (QueryUtils::fetchRecords("SELECT `option_id`, `codes` FROM `list_options` WHERE `list_id` = 'reaction' AND `activity` = 1") as $r) {
                foreach (explode(';', (string)$r['codes']) as $code) {
                    if (str_starts_with($code, 'SNOMED-CT:')) {
                        $this->cache['reaction'][substr($code, 10)] ??= (string)$r['option_id'];
                    }
                }
            }
        }
        return $this->cache['reaction'][$snomed] ?? null;
    }

    public function userByNpi(string $npi): ?int
    {
        $row = QueryUtils::fetchRecords("SELECT `id` FROM `users` WHERE `npi` = ? AND `active` = 1 ORDER BY `id` LIMIT 1", [$npi])[0] ?? null;
        return $row === null ? null : (int)$row['id'];
    }

    /** Office Visit when it exists and is active, else the first active patient category. */
    public function visitCategory(): int
    {
        if ($this->category === null) {
            $rows = QueryUtils::fetchRecords(
                "SELECT `pc_catid`, `pc_constant_id` FROM `openemr_postcalendar_categories` WHERE `pc_cattype` = 0 AND `pc_active` = 1 ORDER BY (`pc_constant_id` = 'office_visit') DESC, `pc_catid`",
            );
            $this->category = (int)($rows[0]['pc_catid'] ?? 5);
        }
        return $this->category;
    }

    /** The primary business entity, else the first service location. */
    public function facility(): int
    {
        if ($this->facility === null) {
            $rows = QueryUtils::fetchRecords('SELECT `id` FROM `facility` ORDER BY `primary_business_entity` DESC, `service_location` DESC, `id` LIMIT 1');
            $this->facility = (int)($rows[0]['id'] ?? 0);
        }
        return $this->facility;
    }

    private function byNotes(string $list, string $code): ?string
    {
        if (!isset($this->cache[$list])) {
            $this->cache[$list] = [];
            // Case matters: "English" is the language, "english" a race entry; the first active option wins.
            foreach (QueryUtils::fetchRecords("SELECT `option_id`, `notes` FROM `list_options` WHERE `list_id` = ? AND `activity` = 1 AND `notes` <> '' ORDER BY `seq`, `option_id`", [$list]) as $r) {
                $this->cache[$list][strtolower(trim((string)$r['notes']))] ??= (string)$r['option_id'];
            }
        }
        return $this->cache[$list][strtolower($code)] ?? null;
    }
}
