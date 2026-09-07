<?php

use App\Domains\People\Tests\Support\GlobalHelperScan;

/*
 * Unit cover for the helper collision guard's source reading (see
 * Tests/Feature/GlobalHelperCollisionTest.php). Inline sources only: the
 * guard walks the real Tests/ trees, so nothing here may declare a helper.
 */

test('the same global name in two sources is reported once with both files', function (): void {
    expect(GlobalHelperScan::duplicates([
        'Connector/Tests/Feature/CutoverTest.php' => "<?php\nfunction cutoverFixture(): array { return []; }\n",
        'Leave/Tests/Feature/CutoverTest.php' => "<?php\n\nfunction cutoverFixture(): array\n{\n    return [];\n}\n",
    ]))->toBe(['cutoverFixture' => ['Connector/Tests/Feature/CutoverTest.php', 'Leave/Tests/Feature/CutoverTest.php']]);
});

test('a namespaced function is not a global', function (): void {
    expect(GlobalHelperScan::duplicates([
        'a.php' => "<?php\nnamespace X;\nfunction cutoverFixture() {}\n",
        'b.php' => "<?php\nfunction cutoverFixture() {}\n",
    ]))->toBe([]);
});

test('a function inside a class is not a global', function (): void {
    expect(GlobalHelperScan::functions("<?php\nfinal class Fixture\n{\n    public function cutoverFixture(): void {}\n}\nfunction realHelper() {}\n"))
        ->toBe(['realHelper']);
});

test('a commented-out function is not a declaration', function (): void {
    expect(GlobalHelperScan::functions("<?php\n// function cutoverFixture() {}\n/* function cutoverCounts() {} */\n\$s = 'function inString() {}';\nfunction kept() {}\n"))
        ->toBe(['kept']);
});

test('a closure is not a declaration', function (): void {
    expect(GlobalHelperScan::functions("<?php\ntest('x', function (): void {});\n\$f = fn () => 1;\n"))->toBe([]);
});
