<?php

declare(strict_types=1);

namespace OpenHealthcare\Migration\Tests;

use OpenHealthcare\Migration\Map\Lookups;
use OpenHealthcare\Migration\Map\Mapped;
use OpenHealthcare\Migration\Run\Writer;

/** A fixed set of OpenEMR list options, users and defaults. */
final class FakeLookups implements Lookups
{
    public function race(string $cdcCode): ?string
    {
        return ['2106-3' => 'white', '2054-5' => 'black_or_afri_amer', '2028-9' => 'Asian'][$cdcCode] ?? null;
    }

    public function ethnicity(string $cdcCode): ?string
    {
        return ['2135-2' => 'hisp_or_latin', '2186-5' => 'not_hisp_or_latin'][$cdcCode] ?? null;
    }

    public function language(string $iso): ?string
    {
        return ['en' => 'English', 'es' => 'Spanish'][$iso] ?? null;
    }

    public function reaction(string $snomed): ?string
    {
        return ['247472004' => 'hives'][$snomed] ?? null;
    }

    public function userByNpi(string $npi): ?int
    {
        return $npi === '9999987198' ? 7 : null;
    }

    public function visitCategory(): int
    {
        return 5;
    }

    public function facility(): int
    {
        return 3;
    }
}

/**
 * Records what would be written; patients get pids from 100, other records ids from 1000. A transaction keeps
 * what was written in it only when it finishes, as the database's would.
 */
final class FakeWriter implements Writer
{
    /** @var list<array<string, mixed>> */
    public array $written = [];

    public int $rolledBack = 0;

    private int $ids = 0;

    /**
     * @param array<string, int> $existing    "fname|lname|dob|sex" => pid already in OpenEMR
     * @param string|null        $failId      a source id OpenEMR refuses outright
     * @param string|null        $failAfterId a source id whose first statement is written and whose second fails
     */
    public function __construct(private readonly array $existing = [], private readonly ?string $failId = null, private readonly ?string $failAfterId = null)
    {
    }

    public function transaction(callable $action): mixed
    {
        $before = $this->written;
        try {
            return $action();
        } catch (\Throwable $e) {
            $this->written = $before;
            $this->rolledBack++;
            throw $e;
        }
    }

    public function addToVitals(int $formId, array $values, int $pid, int $encounter): void
    {
        $this->written[] = ['kind' => 'vitals-add', 'id' => (string)$formId, 'pid' => $pid, 'encounter' => $encounter, 'values' => $values];
    }

    public function findPatient(string $fname, string $lname, string $dob, string $sex): ?int
    {
        return $this->existing["$fname|$lname|$dob|$sex"] ?? null;
    }

    public function write(Mapped $record, ?int $pid, ?int $encounter): int
    {
        if ($record->sourceId === $this->failId) {
            throw new \RuntimeException('OpenEMR refused it: lname: too short');
        }
        $this->written[] = ['kind' => $record->kind, 'id' => $record->sourceId, 'pid' => $pid, 'encounter' => $encounter];
        if ($record->sourceId === $this->failAfterId) {
            throw new \RuntimeException('the second statement failed');
        }
        $this->ids++;
        return $record->kind === Mapped::PATIENT ? 99 + $this->ids : 999 + $this->ids;
    }
}
