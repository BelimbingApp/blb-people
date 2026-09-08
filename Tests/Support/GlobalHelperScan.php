<?php

namespace App\Domains\People\Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * The source reading behind the global Pest helper collision guard.
 *
 * Pure source in, names out, so the reading is unit-testable without real
 * files (Tests/Feature/GlobalHelperScanTest.php); the guard in
 * Tests/Feature/GlobalHelperCollisionTest.php walks the Tests/ trees and
 * delegates every file here. Sources are tokenised, never included, so a
 * colliding helper is reported instead of being the fatal it would be at
 * runtime (#393).
 */
final class GlobalHelperScan
{
    /**
     * Top-level function names declared outside any namespace, in source
     * order. Comments, strings, closures, methods and namespaced functions are
     * not declarations.
     *
     * @return list<string>
     */
    public static function functions(string $source): array
    {
        $names = [];
        $depth = 0;
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                // A namespaced file has no globals; a name may follow.
                return [];
            } elseif ($depth === 0 && is_array($token) && $token[0] === T_FUNCTION) {
                $name = self::declaredName($tokens, $i);

                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $sources  pathname => source
     * @return array<string, list<string>> duplicate name => pathnames declaring it
     */
    public static function duplicates(array $sources): array
    {
        $declared = [];

        foreach ($sources as $pathname => $source) {
            foreach (self::functions($source) as $name) {
                $declared[$name][] = $pathname;
            }
        }

        return array_filter($declared, static fn (array $files): bool => count($files) > 1);
    }

    /**
     * @param  array<string, string>  $roots  mount name => domain root whose Tests/ directories are read
     * @return array<string, list<string>> duplicate name => pathnames declaring it
     */
    public static function tree(array $roots): array
    {
        $sources = [];

        foreach ($roots as $mount => $root) {
            $files = Finder::create()
                ->files()
                ->in($root)
                ->path('#(^|/)Tests/#')
                ->name('*.php')
                ->exclude(['vendor', 'node_modules'])
                ->sortByName();

            foreach ($files as $file) {
                $sources[$mount.'/'.$file->getRelativePathname()] = $file->getContents();
            }
        }

        return self::duplicates($sources);
    }

    /** @param  array<string, list<string>>  $duplicates */
    public static function describe(array $duplicates): string
    {
        $lines = [];

        foreach ($duplicates as $name => $files) {
            $lines[] = $name.' ('.implode(', ', $files).')';
        }

        return implode('; ', $lines);
    }

    /**
     * The identifier after a `function` token, or null for a closure or an
     * arrow function; `function &name(` counts.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function declaredName(array $tokens, int $at): ?string
    {
        for ($i = $at + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '&' || (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }
}
