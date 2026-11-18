<?php

namespace App\Modules\Dian\Transmission;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * What SendBillSync receives (annex 1.9, 6.5.7, 6.5.8 and 7.10.2): a zip with a single signed XML,
 * named z{NIT}{ppp}{aa}{dddddddd}.zip and fv{NIT}{ppp}{aa}{dddddddd}.xml, where the NIT has ten
 * digits padded with zeros, ppp is 000 (own software), aa the last two digits of the year and
 * dddddddd the hexadecimal consecutive of the files the company sent that year.
 */
final class DianPackage
{
    /** ppp of a company that issues with its own software (annex 1.9, 6.5.7). */
    private const string OWN_SOFTWARE = '000';

    /**
     * Names for the next file the company sends in $year; takes the next consecutive.
     *
     * @return array{zip: string, xml: string}
     */
    public function nextNames(string $companyId, string $nit, int $year): array
    {
        return self::names($nit, $year, $this->nextConsecutive($companyId, $year));
    }

    /**
     * @return array{zip: string, xml: string}
     */
    public static function names(string $nit, int $year, int $consecutive): array
    {
        $base = str_pad($nit, 10, '0', STR_PAD_LEFT)
            .self::OWN_SOFTWARE
            .substr((string) $year, -2)
            .str_pad(dechex($consecutive), 8, '0', STR_PAD_LEFT);

        return ['zip' => "z{$base}.zip", 'xml' => "fv{$base}.xml"];
    }

    /** The zip with one entry, the signed XML. */
    public function zip(string $xmlFileName, string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dian-zip-') ?: throw new RuntimeException('No temporary file for the zip.');

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The zip could not be created.');
            }

            $zip->addFromString($xmlFileName, $xml);

            if (! $zip->close()) {
                throw new RuntimeException('The zip could not be written.');
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The next consecutive of the company in the year, starting at 1. One atomic statement, so
     * concurrent transmissions never take the same number; it runs inside the caller's transaction.
     */
    private function nextConsecutive(string $companyId, int $year): int
    {
        DB::statement(
            'insert into dian_file_counters (company_id, year, last_number) values (?, ?, last_insert_id(1)) '
            .'on duplicate key update last_number = last_insert_id(last_number + 1)',
            [$companyId, $year],
        );

        return (int) DB::selectOne('select last_insert_id() as number')->number;
    }
}
