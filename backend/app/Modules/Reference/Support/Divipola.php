<?php

namespace App\Modules\Reference\Support;

use App\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Loads departments and municipalities from Data/divipola.json, the DANE DIVIPOLA list as
 * downloaded from datos.gov.co (source and date are recorded in the file).
 */
final class Divipola
{
    public const string FILE = __DIR__.'/../Data/divipola.json';

    public static function import(): void
    {
        $data = json_decode((string) file_get_contents(self::FILE), true, flags: JSON_THROW_ON_ERROR);
        $field = fn (array $record, string $key): string => Json::text($record, $key) ?? throw new UnexpectedValueException("A DIVIPOLA record has no {$key}.");

        $departments = [];
        $municipalities = [];

        foreach (Json::objects($data, 'records') as $record) {
            $departments[$field($record, 'cod_dpto')] ??= [
                'id' => self::ulid(),
                'code' => $field($record, 'cod_dpto'),
                'name' => PlaceName::format($field($record, 'dpto')),
            ];

            $municipalities[] = [
                'id' => self::ulid(),
                'code' => $field($record, 'cod_mpio'),
                'department_code' => $field($record, 'cod_dpto'),
                'name' => PlaceName::format($field($record, 'nom_mpio')),
            ];
        }

        DB::table('departments')->insert(array_values($departments));

        foreach (array_chunk($municipalities, 500) as $chunk) {
            DB::table('municipalities')->insert($chunk);
        }
    }

    /** Same format as the HasUlids trait: lower-case ULIDs. */
    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }
}
