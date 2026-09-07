<?php

namespace App\Domains\People\Tests\Support;

/**
 * The tag reading behind the x-ui.table caption guard.
 *
 * Pure string in, findings out, so the guard's parsing is unit-testable
 * without touching real views: Tests/Feature/ViewTableCaptionsTest.php walks
 * the repository and delegates every file here.
 *
 * Contract decisions (blb-people#343): raw <table> elements are out of the
 * contract — only the <x-ui.table> component counts, because only it renders
 * the sr-only caption slot the guard can rely on. <x-ui.table-*> variants
 * keep their skip for the same reason. A table that deliberately has no
 * caption opts out with an explicit `no-caption` attribute, so the choice is
 * visible in the view itself.
 */
final class TableCaptionScan
{
    /**
     * @return list<string> "<pathname>:<line>" findings, in source order.
     */
    public static function missing(string $source, string $pathname): array
    {
        $missing = [];
        $offset = 0;

        while (($start = strpos($source, '<x-ui.table', $offset)) !== false) {
            // Skip <x-ui.table-something> variants; only the table component counts.
            $after = $source[$start + strlen('<x-ui.table')] ?? '';
            if (! in_array($after, [' ', "\n", "\r", "\t", '>', '/'], true)) {
                $offset = $start + 1;

                continue;
            }

            [$tag, $offset] = self::tag($source, $start);

            if (! self::captioned($tag)) {
                $missing[] = $pathname.':'.(substr_count($source, "\n", 0, $start) + 1);
            }
        }

        return $missing;
    }

    /** @return array{string, int} [opening tag, offset to resume after] */
    private static function tag(string $source, int $start): array
    {
        $length = strlen($source);
        $quote = null;

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return [substr($source, $start, $i - $start + 1), $i + 1];
            }
        }

        return [substr($source, $start), $length];
    }

    private static function captioned(string $tag): bool
    {
        if (preg_match('/\sno-caption(\s|>|\/)/', $tag) === 1) {
            return true;
        }

        foreach ([':caption', 'caption'] as $attribute) {
            if (preg_match('/\s'.preg_quote($attribute, '/').'\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/', $tag, $match) !== 1) {
                continue;
            }

            return ! self::blank($match[1]);
        }

        return false;
    }

    private static function blank(string $raw): bool
    {
        // A caption can be quoted twice over: :caption="''" is an empty PHP
        // string inside an empty-looking attribute, so strip layers until the
        // value stops changing and only then ask whether anything is left.
        $inner = trim($raw);

        do {
            $outer = $inner;

            if (strlen($inner) >= 2
                && (($inner[0] === '"' && $inner[-1] === '"') || ($inner[0] === "'" && $inner[-1] === "'"))) {
                $inner = trim(substr($inner, 1, -1));
            }
        } while ($inner !== $outer);

        return $inner === '';
    }
}
