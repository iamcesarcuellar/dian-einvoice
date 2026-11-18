<?php

namespace App\Modules\Reference\Enums\Concerns;

/**
 * A list of codes with an English name, served by GET /api/v1/reference.
 */
trait CodeList
{
    abstract public function label(): string;

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['code' => $case->value, 'name' => $case->label()],
            self::cases(),
        );
    }
}
