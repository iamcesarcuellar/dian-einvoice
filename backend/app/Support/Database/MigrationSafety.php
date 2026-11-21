<?php

namespace App\Support\Database;

/**
 * Whether a migration is safe for a deployment that migrates the database before the functions take the new code
 * (scripts/deploy.sh migrate, then functions): for a few minutes the code of the release before runs on the migrated
 * schema, so a migration may add, never take away. A statement in up() that drops or renames a column or a table
 * breaks that code, unless no release still running reads what it takes away: such a statement carries, in a comment
 * on its line or in the comment lines right above it, the release from which the step is safe and why:
 *
 *   // migration-safety: safe since v1.4.0: no release from v1.4.0 on reads documents.legacy_status.
 *   $table->dropColumn('legacy_status');
 *
 * down() is not read: the database goes forward only (docs/deployment.md). Changes of the type of a column (change())
 * are not read either; the review of the migration covers them.
 */
final class MigrationSafety
{
    /** The annotation: the release from which the step is safe, and a reason of a few words. */
    public const string ANNOTATION = '/migration-safety:\s*safe since (v\d+\.\d+\.\d+)\s*[:,-]\s*\S.{9,}/';

    /** Calls of the schema builder that drop or rename a column or a table. */
    private const string BUILDER = '/(?:->|::)(drop|dropIfExists|dropColumn|dropColumns|renameColumn|rename|dropTimestamps|dropTimestampsTz|dropSoftDeletes|dropSoftDeletesTz|dropRememberToken|dropMorphs|dropNullableMorphs|dropConstrainedForeignId|dropConstrainedForeignIdFor|dropAllTables|dropAllViews)\s*\(/';

    /** SQL of a raw statement that drops, renames or empties a column or a table. */
    private const string SQL = '/\b(?:DROP\s+(?:TABLE|COLUMN)|RENAME\s+(?:TABLE|COLUMN|TO)|ALTER\s+TABLE\b.*\b(?:DROP|RENAME|CHANGE)\b|TRUNCATE\b)/i';

    /**
     * What is unsafe in the up() of a migration file: each statement that takes something away without its annotation.
     *
     * @return list<array{line: int, statement: string}>
     */
    public function findings(string $file): array
    {
        $source = (string) file_get_contents($file);
        $tokens = token_get_all($source);
        [$start, $end] = $this->up($tokens);

        if ($start === null || $end === null) {
            return [];
        }

        // Per line of up(): its code (with its strings) and its comments.
        $code = [];
        $comments = [];
        $line = 1;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : $line;
            $comment = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);

            if ($index >= $start && $index <= $end) {
                foreach (explode("\n", $text) as $offset => $part) {
                    $at = $line + $offset;

                    if ($comment) {
                        $comments[$at] = ($comments[$at] ?? '').$part;
                    } elseif (trim($part) !== '') {
                        $code[$at] = ($code[$at] ?? '').$part;
                    }
                }
            }

            $line += substr_count($text, "\n");
        }

        $findings = [];

        foreach ($code as $at => $text) {
            $strings = $this->strings($tokens, $start, $end, $at);

            if (preg_match(self::BUILDER, $text) !== 1 && preg_match(self::SQL, $strings) !== 1) {
                continue;
            }

            if (! $this->annotated($at, $code, $comments)) {
                $findings[] = ['line' => $at, 'statement' => trim($text)];
            }
        }

        return $findings;
    }

    /**
     * The tokens of the body of up(): from its opening brace to its closing one.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int|null, 1: int|null}
     */
    private function up(array $tokens): array
    {
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = null;

            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];

                if (is_array($candidate) && $candidate[0] === T_STRING) {
                    $name = $candidate[1];

                    break;
                }

                if (! is_array($candidate) || ! in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    break;
                }
            }

            if ($name !== 'up') {
                continue;
            }

            $depth = 0;
            $start = null;

            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];
                $text = is_array($candidate) ? $candidate[1] : $candidate;

                if ($text === '{' || (is_array($candidate) && in_array($candidate[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    $start ??= $next;
                } elseif ($text === '}') {
                    $depth--;

                    if ($depth === 0 && $start !== null) {
                        return [$start, $next];
                    }
                }
            }
        }

        return [null, null];
    }

    /**
     * The text of the string literals of up() on one line, where raw SQL lives.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function strings(array $tokens, int $start, int $end, int $line): string
    {
        $text = '';

        for ($index = $start; $index <= $end; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && $token[2] <= $line && $line <= $token[2] + substr_count($token[1], "\n")) {
                $text .= ' '.$token[1];
            }
        }

        return $text;
    }

    /**
     * Whether a line carries the annotation: in a comment on the line itself, or in the comment lines right above it
     * (without code between them).
     *
     * @param  array<int, string>  $code
     * @param  array<int, string>  $comments
     */
    private function annotated(int $line, array $code, array $comments): bool
    {
        if (preg_match(self::ANNOTATION, $comments[$line] ?? '') === 1) {
            return true;
        }

        for ($above = $line - 1; $above > 0 && isset($comments[$above]) && ! isset($code[$above]); $above--) {
            if (preg_match(self::ANNOTATION, $comments[$above]) === 1) {
                return true;
            }
        }

        return false;
    }
}
