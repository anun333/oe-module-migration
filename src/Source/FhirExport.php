<?php

/**
 * A FHIR R4 export on disk, read whole: the bulk NDJSON files of a $export (one
 * resource per line, a file per type), Bundle JSON files (one per patient, as many
 * single-patient exports and Synthea give), or single resources. Then references
 * between them are resolved in the three forms exports use: "Type/id", the Bundle's
 * "urn:uuid:..." full URLs, and identifier searches such as
 * "Practitioner?identifier=http://hl7.org/fhir/sid/us-npi|1234567893".
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Migration\Source;

final class FhirExport
{
    /** @var array<string, array<string, array<string, mixed>>> type => id => resource */
    private array $byType = [];

    /** @var array<string, array{string, string}> urn:uuid full URL => [type, id] */
    private array $byFullUrl = [];

    /** @var array<string, array{string, string}> "Type|system|value" => [type, id] */
    private array $byIdentifier = [];

    /** @var list<string> files that couldn't be read, or lines that weren't JSON */
    private array $problems = [];

    /** Reads every *.ndjson and *.json file in a folder (not its subfolders). */
    public static function fromDirectory(string $dir): self
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("$dir is not a folder");
        }
        $export = new self();
        $files = array_merge(glob(rtrim($dir, '/') . '/*.ndjson') ?: [], glob(rtrim($dir, '/') . '/*.json') ?: []);
        sort($files);
        foreach ($files as $file) {
            str_ends_with($file, '.ndjson') ? $export->readNdjson($file) : $export->readJson($file);
        }
        return $export;
    }

    /**
     * @param list<array<string, mixed>> $resources for tests: resources or Bundles, as decoded JSON
     */
    public static function fromResources(array $resources): self
    {
        $export = new self();
        foreach ($resources as $r) {
            $export->add($r, null);
        }
        return $export;
    }

    /** @return array<string, int> resource type => how many */
    public function counts(): array
    {
        $out = array_map('count', $this->byType);
        ksort($out);
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function ofType(string $type): array
    {
        return array_values($this->byType[$type] ?? []);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The resource a reference points at, or null: {"reference": "Type/id"}, "urn:uuid:...",
     * "Type?identifier=system|value", or a logical {"identifier": {system, value}} with a type.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $reference, ?string $expectedType = null): ?array
    {
        $key = $this->locate($reference, $expectedType);
        return $key === null ? null : ($this->byType[$key[0]][$key[1]] ?? null);
    }

    /** The [type, id] a reference points at, whether or not the export holds it. */
    public function target(mixed $reference, ?string $expectedType = null): ?array
    {
        if (!is_array($reference)) {
            return null;
        }
        $located = $this->locate($reference, $expectedType);
        if ($located !== null) {
            return $located;
        }
        $ref = $reference['reference'] ?? null;
        if (is_string($ref) && preg_match('#^([A-Z][A-Za-z]+)/([A-Za-z0-9\-.]{1,64})$#', $ref, $m)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    /** @return array{string, string}|null */
    private function locate(mixed $reference, ?string $expectedType): ?array
    {
        if (!is_array($reference)) {
            return null;
        }
        $ref = $reference['reference'] ?? null;
        if (is_string($ref)) {
            if (str_starts_with($ref, 'urn:uuid:')) {
                return $this->byFullUrl[$ref] ?? null;
            }
            if (preg_match('#^([A-Z][A-Za-z]+)/([A-Za-z0-9\-.]{1,64})$#', $ref, $m)) {
                return isset($this->byType[$m[1]][$m[2]]) ? [$m[1], $m[2]] : null;
            }
            if (preg_match('#^([A-Z][A-Za-z]+)\?identifier=([^|]*)\|(.+)$#', $ref, $m)) {
                return $this->byIdentifier[$m[1] . '|' . urldecode($m[2]) . '|' . urldecode($m[3])] ?? null;
            }
            return null;
        }
        $identifier = $reference['identifier'] ?? null;
        $type = is_string($reference['type'] ?? null) ? $reference['type'] : $expectedType;
        if (is_array($identifier) && $type !== null) {
            return $this->byIdentifier[$type . '|' . ($identifier['system'] ?? '') . '|' . ($identifier['value'] ?? '')] ?? null;
        }
        return null;
    }

    private function readNdjson(string $file): void
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            $this->problems[] = basename($file) . ': cannot be read';
            return;
        }
        $line = 0;
        while (($text = fgets($handle)) !== false) {
            $line++;
            if (trim($text) === '') {
                continue;
            }
            $resource = json_decode($text, true);
            if (!is_array($resource)) {
                $this->problems[] = basename($file) . " line $line: not JSON";
                continue;
            }
            $this->add($resource, null);
        }
        fclose($handle);
    }

    private function readJson(string $file): void
    {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            $this->problems[] = basename($file) . ': not JSON';
            return;
        }
        if (($data['resourceType'] ?? null) === null) {
            // Bulk export's own parameters file and other metadata: not a resource.
            return;
        }
        $this->add($data, null);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function add(array $resource, ?string $fullUrl): void
    {
        $type = $resource['resourceType'] ?? null;
        // A bulk export's own Parameters and OperationOutcome files describe the export, not the record.
        if (!is_string($type) || in_array($type, ['Parameters', 'OperationOutcome'], true)) {
            return;
        }
        if ($type === 'Bundle') {
            foreach (is_array($resource['entry'] ?? null) ? $resource['entry'] : [] as $entry) {
                if (is_array($entry) && is_array($entry['resource'] ?? null)) {
                    $this->add($entry['resource'], is_string($entry['fullUrl'] ?? null) ? $entry['fullUrl'] : null);
                }
            }
            return;
        }
        $id = $resource['id'] ?? null;
        if (!is_string($id) || $id === '') {
            $this->problems[] = "$type with no id";
            return;
        }
        $this->byType[$type][$id] = $resource;
        if ($fullUrl !== null) {
            $this->byFullUrl[$fullUrl] = [$type, $id];
        }
        $this->byFullUrl['urn:uuid:' . $id] ??= [$type, $id];
        foreach (is_array($resource['identifier'] ?? null) ? $resource['identifier'] : [] as $identifier) {
            if (is_array($identifier) && is_string($identifier['value'] ?? null)) {
                $this->byIdentifier[$type . '|' . ($identifier['system'] ?? '') . '|' . $identifier['value']] = [$type, $id];
            }
        }
    }
}
