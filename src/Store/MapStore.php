<?php

/**
 * The module's own tables: runs, where each imported resource went, and what was skipped
 * or failed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Store;

use OpenEMR\Common\Database\QueryUtils;

final class MapStore
{
    public function startRun(string $mode, string $system, string $source, string $runBy): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `migration_run` (`mode`, `source_system`, `source`, `run_by`) VALUES (?, ?, ?, ?)',
            [$mode, $system, $source, $runBy],
        );
    }

    /** @param array<string, mixed> $report */
    public function finishRun(int $runId, array $report): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `migration_run` SET `finished_at` = NOW(), `report_json` = ? WHERE `id` = ?',
            [json_encode($report, JSON_THROW_ON_ERROR), $runId],
        );
    }

    /**
     * Everything already imported from a system, by "Type/id".
     *
     * @return array<string, array{target: string, targetId: int, pid: int}>
     */
    public function mapped(string $system): array
    {
        $out = [];
        $rows = QueryUtils::fetchRecords(
            'SELECT `resource_type`, `source_id`, `target`, `target_id`, `pid` FROM `migration_map` WHERE `source_system` = ?',
            [$system],
        );
        foreach ($rows as $r) {
            $out[$r['resource_type'] . '/' . $r['source_id']] = ['target' => (string)$r['target'], 'targetId' => (int)$r['target_id'], 'pid' => (int)$r['pid']];
        }
        return $out;
    }

    public function map(string $system, string $type, string $sourceId, string $target, int $targetId, int $pid, int $runId, bool $linked = false): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `migration_map` (`source_system`, `resource_type`, `source_id`, `target`, `target_id`, `pid`, `linked`, `run_id`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$system, $type, $sourceId, $target, $targetId, $pid, $linked ? 1 : 0, $runId],
        );
    }

    public function issue(int $runId, string $type, string $sourceId, string $level, string $message): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `migration_issue` (`run_id`, `resource_type`, `source_id`, `level`, `message`) VALUES (?, ?, ?, ?, ?)',
            [$runId, $type, $sourceId, $level, $message],
        );
    }

    /** @return list<array{id: int, mode: string, system: string, source: string, started: string, finished: string, runBy: string, report: array<string, mixed>}> newest first */
    public function runs(int $limit = 50): array
    {
        $rows = QueryUtils::fetchRecords('SELECT * FROM `migration_run` ORDER BY `id` DESC LIMIT ' . max(1, $limit));
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'mode' => (string)$r['mode'],
            'system' => (string)$r['source_system'],
            'source' => (string)$r['source'],
            'started' => (string)$r['started_at'],
            'finished' => (string)($r['finished_at'] ?? ''),
            'runBy' => (string)$r['run_by'],
            'report' => is_array($d = json_decode((string)($r['report_json'] ?? ''), true)) ? $d : [],
        ], $rows);
    }

    /** @return list<array{type: string, sourceId: string, level: string, message: string}> */
    public function issues(int $runId): array
    {
        return array_map(static fn(array $r): array => [
            'type' => (string)$r['resource_type'],
            'sourceId' => (string)$r['source_id'],
            'level' => (string)$r['level'],
            'message' => (string)$r['message'],
        ], QueryUtils::fetchRecords('SELECT `resource_type`, `source_id`, `level`, `message` FROM `migration_issue` WHERE `run_id` = ? ORDER BY `id`', [$runId]));
    }
}
