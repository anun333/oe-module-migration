<?php

/**
 * A run's counts as text for the command line and as rows for the page and its CSV.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Run;

final class Report
{
    /**
     * @param array<string, array{read: int, imported: int, already: int, skipped: array<string, int>, failed: int}> $report
     * @return list<array{type: string, read: int, imported: int, already: int, skipped: int, failed: int, reasons: string}>
     */
    public static function rows(array $report): array
    {
        ksort($report);
        $rows = [];
        foreach ($report as $type => $r) {
            arsort($r['skipped']);
            $rows[] = [
                'type' => $type,
                'read' => $r['read'],
                'imported' => $r['imported'],
                'already' => $r['already'],
                'skipped' => array_sum($r['skipped']),
                'failed' => $r['failed'],
                'reasons' => implode('; ', array_map(static fn(string $why, int $n): string => "$n $why", array_keys($r['skipped']), $r['skipped'])),
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, array{read: int, imported: int, already: int, skipped: array<string, int>, failed: int}> $report
     */
    public static function text(array $report, bool $dry): string
    {
        $out = sprintf("  %-26s %6s %8s %8s %8s %7s\n", 'resource', 'read', $dry ? 'would' : 'imported', 'already', 'skipped', 'failed');
        foreach (self::rows($report) as $r) {
            $out .= sprintf("  %-26s %6d %8d %8d %8d %7d\n", $r['type'], $r['read'], $r['imported'], $r['already'], $r['skipped'], $r['failed']);
            if ($r['reasons'] !== '') {
                $out .= '      skipped: ' . $r['reasons'] . "\n";
            }
        }
        return $out;
    }
}
