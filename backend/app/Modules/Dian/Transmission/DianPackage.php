<?php

namespace App\Modules\Dian\Transmission;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * File names of annex 1.9 (6.5.7 and 6.5.8) and the zips that carry the files. What SendBillSync
 * receives (7.10.2) is a zip with a single signed XML, named z{NIT}{ppp}{aa}{dddddddd}.zip and
 * fv{NIT}{ppp}{aa}{dddddddd}.xml, where the NIT has ten digits padded with zeros, ppp is 000 (own
 * software), aa the last two digits of the year and dddddddd the hexadecimal consecutive of the
 * files of that prefix the company produced that year. The AttachedDocument delivered to the
 * customer is named ad{...}.xml with its own consecutive, and its zip z{...}.zip with the same one.
 */
final class DianPackage
{
    /** Prefix of an invoice sent to the DIAN (6.5.7). */
    public const string INVOICE = 'fv';

    /** Prefix of an AttachedDocument (6.5.7). */
    public const string ATTACHED_DOCUMENT = 'ad';

    /** ppp of a company that issues with its own software (annex 1.9, 6.5.7). */
    private const string OWN_SOFTWARE = '000';

    /**
     * Names for the next invoice the company sends to the DIAN in $year; takes the next consecutive.
     *
     * @return array{zip: string, xml: string}
     */
    public function nextNames(string $companyId, string $nit, int $year): array
    {
        return self::names($nit, $year, $this->nextConsecutive($companyId, $year, self::INVOICE));
    }

    /**
     * The common part of the next names of a prefix, {NIT}{ppp}{aa}{dddddddd}: the AttachedDocument
     * is "ad" plus it, and its zip "z" plus it.
     */
    public function nextBase(string $companyId, string $nit, int $year, string $prefix): string
    {
        return self::base($nit, $year, $this->nextConsecutive($companyId, $year, $prefix));
    }

    /**
     * @return array{zip: string, xml: string}
     */
    public static function names(string $nit, int $year, int $consecutive): array
    {
        $base = self::base($nit, $year, $consecutive);

        return ['zip' => "z{$base}.zip", 'xml' => self::INVOICE."{$base}.xml"];
    }

    public static function base(string $nit, int $year, int $consecutive): string
    {
        return str_pad($nit, 10, '0', STR_PAD_LEFT)
            .self::OWN_SOFTWARE
            .substr((string) $year, -2)
            .str_pad(dechex($consecutive), 8, '0', STR_PAD_LEFT);
    }

    /** The consecutive written in a base, as a decimal number. */
    public static function consecutiveOf(string $base): int
    {
        return (int) hexdec(substr($base, -8));
    }

    /** The zip with one entry, the signed XML. */
    public function zip(string $xmlFileName, string $xml): string
    {
        return $this->archive([$xmlFileName => $xml]);
    }

    /**
     * A zip with the given entries, in order: file name => contents.
     *
     * @param  array<string, string>  $entries
     */
    public function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dian-zip-') ?: throw new RuntimeException('No temporary file for the zip.');

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The zip could not be created.');
            }

            foreach ($entries as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            if (! $zip->close()) {
                throw new RuntimeException('The zip could not be written.');
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The next consecutive of a prefix for the company in the year, starting at 1. One atomic
     * statement, so concurrent steps never take the same number; it runs inside the caller's
     * transaction.
     */
    private function nextConsecutive(string $companyId, int $year, string $prefix): int
    {
        DB::statement(
            'insert into dian_file_counters (company_id, year, prefix, last_number) values (?, ?, ?, last_insert_id(1)) '
            .'on duplicate key update last_number = last_insert_id(last_number + 1)',
            [$companyId, $year, $prefix],
        );

        return (int) DB::selectOne('select last_insert_id() as number')->number;
    }
}
