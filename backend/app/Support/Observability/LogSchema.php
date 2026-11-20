<?php

namespace App\Support\Observability;

use RuntimeException;

/**
 * The schema of the structured log line (resources/observability/log-schema.json), read once per process: the
 * fields of every line in their order, the keys detail may hold, the events, the outcomes, the levels, the
 * functions and the limits. The formatter writes exactly these fields; the tests hold every consumer to them.
 */
final class LogSchema
{
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        if (self::$schema === null) {
            $path = config()->string('observability.schema');
            $json = is_file($path) ? file_get_contents($path) : false;
            $decoded = is_string($json) ? json_decode($json, true) : null;

            if (! is_array($decoded) || ! is_array($decoded['fields'] ?? null)) {
                throw new RuntimeException('The schema of the log lines (config observability.schema) cannot be read.');
            }

            /** @var array<string, mixed> $decoded */
            self::$schema = $decoded;
        }

        return self::$schema;
    }

    /**
     * Every field of a line, in its order, with whether it may be null and whether it may be left out.
     *
     * @return array<string, array{type: string, nullable: bool, optional: bool, values: list<string>|null}>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::list('fields') as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }

            $values = $field['values'] ?? null;
            $fields[$field['name']] = [
                'type' => is_string($field['type'] ?? null) ? $field['type'] : 'string',
                'nullable' => ($field['nullable'] ?? false) === true,
                'optional' => ($field['optional'] ?? false) === true,
                'values' => is_array($values) ? array_values(array_filter($values, is_string(...))) : null,
            ];
        }

        return $fields;
    }

    /**
     * The keys detail may hold, with their type: string, integer, number or boolean.
     *
     * @return array<string, string>
     */
    public static function detail(): array
    {
        $detail = [];

        foreach (self::map('detail') as $key => $definition) {
            $detail[(string) $key] = is_array($definition) && is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
        }

        return $detail;
    }

    /**
     * The event names, with what each one means.
     *
     * @return array<string, string>
     */
    public static function events(): array
    {
        return array_map(fn (mixed $description): string => is_string($description) ? $description : '', self::map('events'));
    }

    /**
     * The values a field may take, when the schema lists them.
     *
     * @return list<string>
     */
    public static function values(string $field): array
    {
        return self::fields()[$field]['values'] ?? [];
    }

    public static function limit(string $name, int $default): int
    {
        $limits = self::map('limits');

        return is_int($limits[$name] ?? null) ? $limits[$name] : $default;
    }

    /** The pattern a field must match, without delimiters, when the schema gives one. */
    public static function pattern(string $field): ?string
    {
        foreach (self::list('fields') as $definition) {
            if (is_array($definition) && ($definition['name'] ?? null) === $field) {
                return is_string($definition['pattern'] ?? null) ? $definition['pattern'] : null;
            }
        }

        return null;
    }

    /** The pattern of a correlation id, without delimiters. */
    public static function correlationPattern(): string
    {
        $correlation = self::map('correlation');

        return is_string($correlation['pattern'] ?? null) ? $correlation['pattern'] : '^[0-9a-hjkmnp-tv-z]{26}$';
    }

    public static function correlationHeader(): string
    {
        $correlation = self::map('correlation');

        return is_string($correlation['header'] ?? null) ? $correlation['header'] : 'X-Correlation-Id';
    }

    /** Forgets the schema read, for a test that changes config observability.schema. */
    public static function forget(): void
    {
        self::$schema = null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(string $key): array
    {
        $value = self::definition()[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(string $key): array
    {
        $value = self::definition()[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
