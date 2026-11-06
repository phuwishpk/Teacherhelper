<?php

namespace App\Domain\Firestore;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * PHP values -> Firestore REST `Value` objects (DESIGN §38.4): string, int,
 * float, bool, null, DateTimeInterface (timestampValue, UTC RFC 3339), lists
 * (arrayValue) and string-keyed arrays (mapValue). Anything else is a bug.
 */
final class FirestoreValues
{
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[(string) $name] = self::encode($value);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function encode(mixed $value): array
    {
        return match (true) {
            $value === null => ['nullValue' => null],
            is_bool($value) => ['booleanValue' => $value],
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_string($value) => ['stringValue' => $value],
            $value instanceof DateTimeInterface => ['timestampValue' => CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z')],
            is_array($value) && array_is_list($value) => ['arrayValue' => ['values' => array_map(self::encode(...), $value)]],
            is_array($value) => ['mapValue' => ['fields' => self::fields($value)]],
            default => throw new \InvalidArgumentException('Firestore cannot store a '.get_debug_type($value)),
        };
    }

    /**
     * The opposite direction, for `firestore-check` and tests: a `fields`
     * object from a document -> plain PHP values.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function decodeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[$name] = self::decode(is_array($value) ? $value : []);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $value */
    public static function decode(array $value): mixed
    {
        if (array_key_exists('nullValue', $value)) {
            return null;
        }
        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }
        if (array_key_exists('arrayValue', $value)) {
            return array_map(fn ($v) => self::decode(is_array($v) ? $v : []), $value['arrayValue']['values'] ?? []);
        }
        if (array_key_exists('mapValue', $value)) {
            return self::decodeFields($value['mapValue']['fields'] ?? []);
        }
        foreach (['booleanValue', 'doubleValue', 'stringValue', 'timestampValue'] as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }

        return null;
    }
}
