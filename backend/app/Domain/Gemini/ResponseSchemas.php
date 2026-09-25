<?php

namespace App\Domain\Gemini;

use InvalidArgumentException;

/**
 * JSON Schemas of Gemini's structured output (DESIGN §10.3–§10.6), one file
 * per prompt in schemas/{purpose}.{type}.json. The same schema is sent as
 * generationConfig.responseJsonSchema and checked again on the server
 * (SchemaValidator).
 */
final class ResponseSchemas
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public static function get(string $purpose, string $type): array
    {
        $name = $purpose.'.'.$type;
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $name) !== 1) {
            throw new InvalidArgumentException("invalid schema name {$name}");
        }
        $file = __DIR__.'/schemas/'.$name.'.json';
        if (! is_file($file)) {
            throw new InvalidArgumentException("no response schema {$name}");
        }

        return self::$cache[$name] = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }
}
