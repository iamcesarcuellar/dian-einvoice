<?php

namespace App\Support\ApiDocs;

use LogicException;

/**
 * The Spanish of the API reference, from resources/api-reference/es.json: one catalog keyed by the English text, so a
 * text that repeats (a header of every route, a response shared by invoices, credit notes and debit notes) is
 * translated once. Its texts are those a person reads: the summaries, the descriptions and the names of the groups.
 * What stays in English by technical standard is no text of the catalog: paths, operationId, the names of parameters,
 * fields and schemas, enum values, examples, status codes, header names and media types.
 *
 * A text is translated block by block: a paragraph, a list item, a table row or a heading, with its lines joined. A
 * change to one paragraph of the English leaves the rest of its translation in place. A block the catalog lacks stays in English,
 * and make docs-check (e2e/docs/reference.mjs, which splits the texts the same way) fails until it is translated.
 */
final class ReferenceCatalog
{
    private const string FILE = 'api-reference/es.json';

    private static ?self $spanish = null;

    /**
     * @param  array<string, string>  $blocks  The Spanish of each English block, the blocks that read the same included.
     */
    private function __construct(private readonly array $blocks, private readonly string $pageTitle) {}

    public static function spanish(): self
    {
        if (self::$spanish !== null) {
            return self::$spanish;
        }

        $catalog = json_decode((string) file_get_contents(resource_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);
        $translations = is_array($catalog) ? ($catalog['translations'] ?? null) : null;
        $same = is_array($catalog) ? ($catalog['same'] ?? null) : null;
        $title = is_array($catalog) && is_array($catalog['page'] ?? null) ? ($catalog['page']['title'] ?? null) : null;

        if (! is_array($translations) || ! is_array($same) || ! is_string($title)) {
            throw new LogicException('resources/'.self::FILE.' needs translations, same and page.title.');
        }

        $blocks = [];

        foreach ($translations as $english => $spanish) {
            $blocks[(string) $english] = is_string($spanish) ? $spanish : throw new LogicException("The translation of \"{$english}\" is not a string.");
        }

        foreach (array_keys($same) as $english) {
            $blocks[(string) $english] = (string) $english;
        }

        return self::$spanish = new self($blocks, $title);
    }

    /** The title of the Spanish page, or the English one while the catalog has none. */
    public function pageTitle(string $english): string
    {
        return $this->pageTitle === '' ? $english : $this->pageTitle;
    }

    /** A text of the reference in Spanish, block by block; a block the catalog lacks stays as it is. */
    public function translate(string $text): string
    {
        $blocks = preg_split('/\n[ \t]*\n/', $text);

        return implode("\n\n", array_map($this->translateBlock(...), $blocks === false ? [$text] : $blocks));
    }

    /**
     * A block: headings, table rows, list items (each with the lines that continue it) and paragraph lines, each unit on
     * a line of its own with its marks kept: the # of a heading, the - of an item, the outer | of a row. A unit
     * without a letter, such as a table's |---|, stays as it is.
     */
    private function translateBlock(string $block): string
    {
        /** @var list<string> $marks */
        $marks = [];
        /** @var list<string> $ends */
        $ends = [];
        /** @var array<int, list<string>> $lines */
        $lines = [];
        $continues = false;

        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^(#{1,6} +)(.*)$/', $line, $match) === 1) {
                [$marks[], $lines[], $ends[], $continues] = [$match[1], [$match[2]], '', false];
            } elseif (preg_match('/^([ \t]*\|[ \t]*)(.*?)([ \t]*\|?[ \t]*)$/', $line, $match) === 1) {
                [$marks[], $lines[], $ends[], $continues] = [$match[1], [$match[2]], $match[3], false];
            } elseif (preg_match('/^([ \t]*(?:[-*+]|\d+[.)]) +)(.*)$/', $line, $match) === 1) {
                [$marks[], $lines[], $ends[], $continues] = [$match[1], [$match[2]], '', true];
            } elseif ($continues) {
                $lines[array_key_last($lines)][] = $line;
            } else {
                [$marks[], $lines[], $ends[], $continues] = ['', [$line], '', true];
            }
        }

        $units = [];

        foreach ($marks as $index => $mark) {
            $english = trim(implode(' ', array_map(trim(...), $lines[$index] ?? [])));
            $units[] = $mark.(preg_match('/\pL/u', $english) === 1 ? ($this->blocks[$english] ?? $english) : $english).($ends[$index] ?? '');
        }

        return implode("\n", $units);
    }
}
