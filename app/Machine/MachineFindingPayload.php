<?php

namespace App\Machine;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use stdClass;
use Throwable;

final class MachineFindingPayload
{
    public const int MAX_FINDINGS = 25;

    public const int MAX_ENCODED_BYTES = 4080;

    public const int MAX_MEMBER_ENCODED_BYTES = 203;

    public const int MAX_CODE_BYTES = 64;

    public const int MAX_SEVERITY_BYTES = 9;

    public const int MAX_OBSERVED_AT_BYTES = 29;

    /** @return array{accepted: list<array{code: string, recovered: bool, observed_at: CarbonImmutable}>, ignored_unknown: int, ignored_invalid: int} */
    public function parse(mixed $raw): array
    {
        if ($raw === null) {
            return ['accepted' => [], 'ignored_unknown' => 0, 'ignored_invalid' => 0];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return ['accepted' => [], 'ignored_unknown' => 0, 'ignored_invalid' => 1];
        }
        if (count($raw) > self::MAX_FINDINGS) {
            return ['accepted' => [], 'ignored_unknown' => 0, 'ignored_invalid' => count($raw)];
        }
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return ['accepted' => [], 'ignored_unknown' => 0, 'ignored_invalid' => count($raw)];
        }

        $accepted = [];
        $unknown = 0;
        $invalid = 0;
        foreach ($raw as $index => $finding) {
            if (! $finding instanceof stdClass) {
                $invalid++;

                continue;
            }
            $encodedFinding = json_encode($finding, JSON_UNESCAPED_SLASHES);
            if (! is_string($encodedFinding) || strlen($encodedFinding) > self::MAX_MEMBER_ENCODED_BYTES) {
                $invalid++;

                continue;
            }
            $values = get_object_vars($finding);
            $keys = array_keys($values);
            sort($keys, SORT_STRING);
            if (count($values) !== 3 || $keys !== ['code', 'observed_at', 'severity']
                || ! is_string($values['code'] ?? null)
                || strlen($values['code']) > self::MAX_CODE_BYTES
                || ! is_string($values['severity'] ?? null)
                || strlen($values['severity']) > self::MAX_SEVERITY_BYTES
                || ! in_array($values['severity'], ['warning', 'critical', 'recovered'], true)
                || ! is_string($values['observed_at'] ?? null)
                || strlen($values['observed_at']) > self::MAX_OBSERVED_AT_BYTES) {
                $invalid++;

                continue;
            }
            if (! hash_equals(MachineConditionNotifier::RUNNER_CHECK, $values['code'])) {
                $unknown++;

                continue;
            }
            $observed = $this->timestamp($values['observed_at']);
            if (! $observed instanceof CarbonImmutable) {
                $invalid++;

                continue;
            }

            $accepted[] = [
                'code' => MachineConditionNotifier::RUNNER_CHECK,
                'recovered' => $values['severity'] === 'recovered',
                'observed_at' => $observed,
                '_input_index' => $index,
            ];
        }

        usort($accepted, fn (array $left, array $right): int => [
            $left['observed_at']->getTimestamp(), $left['_input_index'],
        ] <=> [
            $right['observed_at']->getTimestamp(), $right['_input_index'],
        ]);
        $accepted = array_map(function (array $finding): array {
            unset($finding['_input_index']);

            return $finding;
        }, $accepted);

        return ['accepted' => $accepted, 'ignored_unknown' => $unknown, 'ignored_invalid' => $invalid];
    }

    private function timestamp(string $value): CarbonImmutable|false
    {
        foreach ([DateTimeInterface::RFC3339, DateTimeInterface::RFC3339_EXTENDED] as $format) {
            try {
                $timestamp = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($timestamp instanceof CarbonImmutable && $timestamp->format($format) === $value) {
                    return $timestamp;
                }
            } catch (Throwable) {
                // Try the other RFC 3339 precision.
            }
        }

        return false;
    }
}
