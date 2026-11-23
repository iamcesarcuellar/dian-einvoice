<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backend's two languages say the same things: every Spanish file (lang/es and each module's Lang/es) has an English
 * one, and every English file of a module has a Spanish one; both have the same keys, each line the same placeholders
 * (":name") and, when it is a plural, the same forms. English may leave a field name null (the framework's default);
 * Spanish names every field. lang/en only adds to the framework's English files, which the comparison takes as the
 * English of validation, auth and passwords.
 */
final class TranslationFilesTest extends TestCase
{
    private const string FRAMEWORK = 'vendor/laravel/framework/src/Illuminate/Translation/lang/en';

    /**
     * Date patterns: each language writes a date its own way from the same parts (ColombianFormat::date fills in all
     * of them), so their placeholders need only be some of those parts. English writes "19 Nov 2026", Spanish
     * "19/11/2026".
     */
    private const array DATE_PATTERNS = ['app/Modules/Delivery/Lang/es/format.php' => ['date']];

    private const array DATE_PARTS = [':day', ':dd', ':mm', ':month', ':year'];

    /**
     * The language folders of the backend: the application's and each module's.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function folders(): array
    {
        $root = dirname(__DIR__, 3);
        $folders = ['lang' => [$root.'/lang/en', $root.'/lang/es']];

        foreach (glob($root.'/app/Modules/*/Lang', GLOB_ONLYDIR) ?: [] as $folder) {
            $folders[basename(dirname($folder))] = [$folder.'/en', $folder.'/es'];
        }

        return $folders;
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function pairs(): iterable
    {
        foreach (self::folders() as [$english, $spanish]) {
            foreach (glob($spanish.'/*.php') ?: [] as $file) {
                yield self::fileName($file) => [self::englishFile($english, basename($file)), $file];
            }
        }
    }

    public function test_every_module_file_is_in_both_languages(): void
    {
        $missing = [];

        foreach (self::folders() as $name => [$english, $spanish]) {
            $this->assertDirectoryExists($spanish, "{$name} has English lines and no Spanish ones");

            foreach (glob($english.'/*.php') ?: [] as $file) {
                if (! is_file($spanish.'/'.basename($file))) {
                    $missing[] = self::fileName($file);
                }
            }

            foreach (glob($spanish.'/*.php') ?: [] as $file) {
                if (self::englishFile($english, basename($file)) === null) {
                    $missing[] = self::fileName($file);
                }
            }
        }

        $this->assertSame([], $missing, 'files with no counterpart in the other language');
    }

    #[DataProvider('pairs')]
    public function test_the_spanish_lines_match_the_english_ones(?string $english, string $spanish): void
    {
        $this->assertNotNull($english, 'the English file of '.self::fileName($spanish));

        $theirs = self::lines(self::load($english));
        $ours = self::lines(self::load($spanish));

        $this->assertSame([], array_values(array_diff(array_keys($ours), array_keys($theirs))), 'keys only in Spanish');
        $this->assertSame([], array_values(array_diff(array_keys($theirs), array_keys($ours))), 'keys missing from Spanish');

        $problems = [];

        foreach ($theirs as $key => $line) {
            $translated = $ours[$key] ?? null;

            if (! is_string($translated) || trim($translated) === '') {
                $problems[] = "{$key}: no Spanish text";

                continue;
            }

            if ($line === null) {
                continue;
            }

            $forms = self::forms($line);
            $translatedForms = self::forms($translated);

            if (count($forms) !== count($translatedForms)) {
                $problems[] = sprintf('%s: %d plural forms in English, %d in Spanish', $key, count($forms), count($translatedForms));

                continue;
            }

            if (in_array($key, self::DATE_PATTERNS[self::fileName($spanish)] ?? [], true)) {
                if (array_diff(self::placeholders($translated), self::DATE_PARTS) !== [] || ! in_array(':year', self::placeholders($translated), true)) {
                    $problems[] = "{$key}: a date pattern takes only :day, :dd, :month, :mm and :year, the year included";
                }

                continue;
            }

            if (self::placeholders($line) !== self::placeholders($translated)) {
                $problems[] = sprintf('%s: placeholders %s in English, %s in Spanish', $key, implode(' ', self::placeholders($line)), implode(' ', self::placeholders($translated)));
            }
        }

        $this->assertSame([], $problems);
    }

    /** The English file of a Spanish one: the module's, or the framework's merged with the application's additions. */
    private static function englishFile(string $folder, string $file): ?string
    {
        foreach ([$folder.'/'.$file, dirname(__DIR__, 3).'/'.self::FRAMEWORK.'/'.$file] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The lines of a file; for an application file, the framework's English lines of the same name first, as Laravel
     * merges them.
     *
     * @return array<mixed>
     */
    private static function load(string $file): array
    {
        $root = dirname(__DIR__, 3);
        $framework = $root.'/'.self::FRAMEWORK.'/'.basename($file);
        $lines = str_starts_with($file, $root.'/lang/en/') && is_file($framework) ? self::read($framework) : [];

        return array_replace_recursive($lines, self::read($file));
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $file): array
    {
        $lines = require $file;

        return is_array($lines) ? $lines : [];
    }

    /**
     * Every line by its dotted key; a null line (a field name left to the framework) stays null.
     *
     * @param  array<mixed>  $tree
     * @return array<string, string|null>
     */
    private static function lines(array $tree, string $prefix = ''): array
    {
        $lines = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== []) {
                $lines += self::lines($value, $path);
            } elseif (is_array($value)) {
                $lines[$path] = '[]';
            } else {
                $lines[$path] = is_string($value) ? $value : null;
            }
        }

        return $lines;
    }

    /**
     * The forms of a line counted with trans_choice: "{0} none|{1} one|[2,*] many" has three; any other line one.
     *
     * @return list<string>
     */
    private static function forms(string $line): array
    {
        return preg_match('/(^|\|)\s*(\{\d+\}|\[\d+,(\d+|\*)\])/', $line) === 1 ? explode('|', $line) : [$line];
    }

    /**
     * The placeholders of a line, such as [":attribute", ":max"], each once.
     *
     * @return list<string>
     */
    private static function placeholders(string $line): array
    {
        preg_match_all('/:[a-z_]+/', $line, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }

    private static function fileName(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 3)) + 1);
    }
}
