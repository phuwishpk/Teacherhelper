<?php

namespace App\Domain\Gemini;

/**
 * Server-side check of Gemini's structured output against the JSON Schema
 * the request carried (DESIGN §10.1: always checked again; failing output is
 * invalid_output). Supports the subset the schemas use: type, properties,
 * required, items, enum, minItems/maxItems, minimum/maximum, maxLength.
 * Unknown properties are ignored (callers read only what they know).
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> errors, empty when valid
     */
    public static function validate(array $schema, mixed $value, string $path = '$'): array
    {
        $type = $schema['type'] ?? null;
        if ($type !== null && ! self::hasType($value, (string) $type)) {
            return ["{$path}: expected {$type}"];
        }

        $errors = [];
        if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            $errors[] = "{$path}: not one of ".implode('|', array_map('strval', (array) $schema['enum']));
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value, 'UTF-8') > (int) $schema['maxLength']) {
            $errors[] = "{$path}: longer than {$schema['maxLength']}";
        }
        if ((is_int($value) || is_float($value)) && ! is_bool($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above {$schema['maximum']}";
            }
        }

        if ($type === 'object' && is_array($value)) {
            foreach ((array) ($schema['required'] ?? []) as $field) {
                if (! array_key_exists($field, $value)) {
                    $errors[] = "{$path}.{$field}: required";
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $field => $sub) {
                if (array_key_exists($field, $value)) {
                    array_push($errors, ...self::validate((array) $sub, $value[$field], "{$path}.{$field}"));
                }
            }
        }

        if ($type === 'array' && is_array($value)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $errors[] = "{$path}: fewer than {$schema['minItems']} items";
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items";
            }
            if (isset($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...self::validate((array) $schema['items'], $item, "{$path}[{$i}]"));
                }
            }
        }

        return $errors;
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'null' => $value === null,
            default => false,
        };
    }
}
