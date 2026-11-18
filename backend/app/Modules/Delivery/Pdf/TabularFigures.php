<?php

namespace App\Modules\Delivery\Pdf;

use RuntimeException;

/**
 * Tabular figures for the PDF. Inter draws proportional digits by default and keeps its tabular
 * ones (zero.tf to nine.tf) behind the OpenType feature tnum, which dompdf does not apply (it has
 * no OpenType layout). Instead, this writes a copy of each font whose character map sends 0 to 9
 * straight to the tabular glyphs: every digit of the PDF has the same width and still copies and
 * searches as a real digit, the CUFE included. Only the cmap table changes; the copy is a Modified
 * Version under the SIL Open Font License of Inter, which declares no Reserved Font Name. Copies
 * are written once per container.
 */
final class TabularFigures
{
    private const array DIGITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

    /** The copy of $source in $directory, written the first time it is needed and reused afterwards. */
    public static function copy(string $source, string $directory): string
    {
        $target = $directory.DIRECTORY_SEPARATOR.basename($source);

        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("The font directory {$directory} could not be created.");
        }

        $font = file_get_contents($source);

        if ($font === false) {
            throw new RuntimeException("The font {$source} could not be read.");
        }

        // Written aside and renamed, so a worker never reads a half-written font.
        $temporary = tempnam($directory, 'font-') ?: throw new RuntimeException("No temporary file in {$directory}.");
        file_put_contents($temporary, self::patch($font));
        rename($temporary, $target);

        return $target;
    }

    /** The font with 0 to 9 mapped to its tabular figures. */
    public static function patch(string $font): string
    {
        $tables = self::tables($font);

        foreach (['cmap', 'post', 'head'] as $required) {
            if (! isset($tables[$required])) {
                throw new RuntimeException("The font has no {$required} table.");
            }
        }

        $glyphs = self::glyphNames($tables['post']);
        $map = self::characterMap($tables['cmap']);

        foreach (self::DIGITS as $index => $name) {
            $map[0x30 + $index] = $glyphs[$name.'.tf'] ?? throw new RuntimeException("The font has no tabular figure {$name}.tf.");
        }

        $tables['cmap'] = self::buildCharacterMap($map);

        return self::assemble(substr($font, 0, 4), $tables);
    }

    /**
     * @return array<string, string> Tag => table data.
     */
    private static function tables(string $font): array
    {
        $tables = [];
        $count = self::u16($font, 4);

        for ($index = 0; $index < $count; $index++) {
            $record = 12 + 16 * $index;
            $tables[substr($font, $record, 4)] = substr($font, self::u32($font, $record + 8), self::u32($font, $record + 12));
        }

        return $tables;
    }

    /**
     * Glyph names stored in the font (post table, format 2).
     *
     * @return array<string, int> Name => glyph id.
     */
    private static function glyphNames(string $post): array
    {
        if (self::u32($post, 0) !== 0x00020000) {
            throw new RuntimeException('The font has no glyph names (post table format 2).');
        }

        $count = self::u16($post, 32);
        $indexes = array_values(unpack('n*', substr($post, 34, 2 * $count)) ?: []);
        $names = [];
        $position = 34 + 2 * $count;
        $length = strlen($post);

        while ($position < $length) {
            $size = ord($post[$position]);
            $names[] = substr($post, $position + 1, $size);
            $position += 1 + $size;
        }

        $glyphs = [];

        foreach ($indexes as $glyph => $index) {
            // Indexes below 258 are the standard Macintosh names, which no tabular figure uses.
            if ($index >= 258 && isset($names[$index - 258])) {
                $glyphs[$names[$index - 258]] = $glyph;
            }
        }

        return $glyphs;
    }

    /**
     * The Unicode character map of the font, from its format 12 subtable, or its format 4 one.
     *
     * @return array<int, int> Code point => glyph id.
     */
    private static function characterMap(string $cmap): array
    {
        $format4 = null;
        $format12 = null;
        $count = self::u16($cmap, 2);

        for ($index = 0; $index < $count; $index++) {
            $record = 4 + 8 * $index;
            $platform = self::u16($cmap, $record);
            $encoding = self::u16($cmap, $record + 2);
            $offset = self::u32($cmap, $record + 4);
            $format = self::u16($cmap, $offset);

            if ($format === 12 && (($platform === 3 && $encoding === 10) || ($platform === 0 && $encoding === 4))) {
                $format12 ??= $offset;
            } elseif ($format === 4 && (($platform === 3 && $encoding === 1) || ($platform === 0 && $encoding === 3))) {
                $format4 ??= $offset;
            }
        }

        $map = [];

        if ($format12 !== null) {
            $groups = self::u32($cmap, $format12 + 12);

            for ($group = 0; $group < $groups; $group++) {
                $position = $format12 + 16 + 12 * $group;
                $start = self::u32($cmap, $position);
                $end = self::u32($cmap, $position + 4);
                $glyph = self::u32($cmap, $position + 8);

                for ($code = $start; $code <= $end; $code++) {
                    $map[$code] = $glyph + ($code - $start);
                }
            }

            return $map;
        }

        if ($format4 === null) {
            throw new RuntimeException('The font has no Unicode character map.');
        }

        $segments = intdiv(self::u16($cmap, $format4 + 6), 2);
        $ends = $format4 + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $ranges = $deltas + 2 * $segments;

        for ($segment = 0; $segment < $segments; $segment++) {
            $end = self::u16($cmap, $ends + 2 * $segment);
            $start = self::u16($cmap, $starts + 2 * $segment);
            $delta = self::u16($cmap, $deltas + 2 * $segment);
            $rangeOffset = self::u16($cmap, $ranges + 2 * $segment);

            for ($code = $start; $code <= $end && $code < 0xFFFF; $code++) {
                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyph = self::u16($cmap, $ranges + 2 * $segment + $rangeOffset + 2 * ($code - $start));
                    $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                }

                if ($glyph !== 0) {
                    $map[$code] = $glyph;
                }
            }
        }

        return $map;
    }

    /**
     * A cmap table with the same four encoding records as Inter: Unicode BMP and full (platform 0,
     * encodings 3 and 4) and Windows BMP and full (platform 3, encodings 1 and 10), pointing to one
     * format 4 and one format 12 subtable built from runs of consecutive characters and glyphs.
     *
     * @param  array<int, int>  $map
     */
    private static function buildCharacterMap(array $map): string
    {
        ksort($map);
        $runs = [];

        foreach ($map as $code => $glyph) {
            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last][1] === $code - 1 && $runs[$last][2] + ($code - $runs[$last][0]) === $glyph) {
                $runs[$last][1] = $code;
            } else {
                $runs[] = [$code, $code, $glyph];
            }
        }

        // Format 4: the runs of the Basic Multilingual Plane, then the closing 0xFFFF segment.
        $segments = [];

        foreach ($runs as [$start, $end, $glyph]) {
            if ($start <= 0xFFFE) {
                $segments[] = [$start, min($end, 0xFFFE), $glyph];
            }
        }

        $segments[] = [0xFFFF, 0xFFFF, 0x10000];
        $count = count($segments);
        [$power, $log] = self::power($count);

        $endCodes = $startCodes = $deltas = $rangeOffsets = '';

        foreach ($segments as [$start, $end, $glyph]) {
            $endCodes .= pack('n', $end);
            $startCodes .= pack('n', $start);
            $deltas .= pack('n', ($glyph - $start) & 0xFFFF);
            $rangeOffsets .= pack('n', 0);
        }

        $format4 = pack('n7', 4, 16 + 8 * $count, 0, 2 * $count, 2 * $power, $log, 2 * $count - 2 * $power)
            .$endCodes.pack('n', 0).$startCodes.$deltas.$rangeOffsets;

        $groups = '';

        foreach ($runs as [$start, $end, $glyph]) {
            $groups .= pack('N3', $start, $end, $glyph);
        }

        $format12 = pack('nnNNN', 12, 0, 16 + 12 * count($runs), 0, count($runs)).$groups;

        $offset4 = 4 + 8 * 4;
        $offset12 = $offset4 + strlen($format4);

        return pack('nn', 0, 4)
            .pack('nnN', 0, 3, $offset4)
            .pack('nnN', 0, 4, $offset12)
            .pack('nnN', 3, 1, $offset4)
            .pack('nnN', 3, 10, $offset12)
            .$format4
            .$format12;
    }

    /**
     * The font file again: table directory sorted by tag, tables aligned to four bytes, their
     * checksums, and the checksum adjustment of the head table.
     *
     * @param  array<string, string>  $tables
     */
    private static function assemble(string $version, array $tables): string
    {
        ksort($tables, SORT_STRING);
        $count = count($tables);
        [$power, $log] = self::power($count);

        $records = '';
        $body = '';
        $offset = 12 + 16 * $count;
        $headOffset = null;

        foreach ($tables as $tag => $data) {
            $tag = (string) $tag;

            if ($tag === 'head') {
                $data = substr_replace($data, "\0\0\0\0", 8, 4);
                $headOffset = $offset;
            }

            $records .= $tag.pack('N3', self::checksum($data), $offset, strlen($data));
            $padded = $data.str_repeat("\0", (4 - strlen($data) % 4) % 4);
            $body .= $padded;
            $offset += strlen($padded);
        }

        $font = $version.pack('n4', $count, 16 * $power, $log, 16 * $count - 16 * $power).$records.$body;
        $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;

        return substr_replace($font, pack('N', $adjustment), (int) $headOffset + 8, 4);
    }

    /**
     * The largest power of two not above $count, and its exponent.
     *
     * @return array{0: int, 1: int}
     */
    private static function power(int $count): array
    {
        $power = 1;
        $log = 0;

        while ($power * 2 <= $count) {
            $power *= 2;
            $log++;
        }

        return [$power, $log];
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;

        foreach (unpack('N*', $data) ?: [] as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('n', $data, $offset)[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('N', $data, $offset)[1];
    }
}
