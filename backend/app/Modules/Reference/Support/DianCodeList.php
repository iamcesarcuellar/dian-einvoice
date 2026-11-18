<?php

namespace App\Modules\Reference\Support;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Reads an official DIAN code list in genericode format, as the toolbox publishes them under
 * "Listas de valores" (copies in resources/dian/codelists): code => name, in the order of the list.
 */
final class DianCodeList
{
    /** @var array<string, array<string, string>> */
    private static array $loaded = [];

    /**
     * @return array<string, string>
     */
    public static function names(string $list): array
    {
        if (isset(self::$loaded[$list])) {
            return self::$loaded[$list];
        }

        $path = resource_path("dian/codelists/{$list}.gc");
        $dom = new DOMDocument;

        if (! is_file($path) || ! $dom->load($path, LIBXML_NONET)) {
            throw new RuntimeException("The DIAN code list {$list} could not be read.");
        }

        $xpath = new DOMXPath($dom);
        $names = [];

        foreach ($xpath->query('//SimpleCodeList/Row') ?: [] as $row) {
            $code = trim((string) $xpath->evaluate('string(Value[@ColumnRef="code"]/SimpleValue)', $row));
            $name = trim(preg_replace('/\s+/', ' ', (string) $xpath->evaluate('string(Value[@ColumnRef="name"]/SimpleValue)', $row)) ?? '');

            if ($code !== '') {
                $names[$code] = $name;
            }
        }

        return self::$loaded[$list] = $names;
    }
}
