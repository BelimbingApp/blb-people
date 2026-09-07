<?php

/*
 * Every <x-ui.table> in a People view must carry a caption so screen readers
 * announce what the table is about. The component renders it sr-only, so the
 * caption never changes the visible layout.
 *
 * SonarCloud flags this in the platform repository, but Domain views are
 * excluded from that project and the Domain repository has no analyser of its
 * own. This test is the guard that the composed domain-ci can run (#310).
 *
 * A table that deliberately has no caption opts out with an explicit
 * `no-caption` attribute, so the choice is visible in the view itself.
 */

use Symfony\Component\Finder\Finder;

it('gives every x-ui.table in a People view a caption or an explicit no-caption opt-out', function () {
    $root = realpath(__DIR__.'/../..');

    $views = Finder::create()
        ->files()
        ->in($root)
        ->path('#(^|/)Views/#')
        ->name('*.blade.php')
        ->exclude(['vendor', 'node_modules']);

    $missing = [];

    foreach ($views as $view) {
        $source = $view->getContents();
        $offset = 0;

        while (($start = strpos($source, '<x-ui.table', $offset)) !== false) {
            // Skip <x-ui.table-something> variants; only the table component counts.
            $after = $source[$start + strlen('<x-ui.table')] ?? '';
            if (! in_array($after, [' ', "\n", "\r", "\t", '>', '/'], true)) {
                $offset = $start + 1;

                continue;
            }

            $end = strpos($source, '>', $start);
            $tag = substr($source, $start, $end === false ? null : $end - $start + 1);
            $offset = $end === false ? strlen($source) : $end + 1;

            if (preg_match('/\s:?caption=/', $tag) || preg_match('/\sno-caption(\s|>|\/)/', $tag)) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $start) + 1;
            $missing[] = $view->getRelativePathname().':'.$line;
        }
    }

    expect($missing)->toBe([], 'Tables without a caption (add :caption="__(...)" or an explicit no-caption attribute): '.implode(', ', $missing));
});
