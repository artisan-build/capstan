<?php

use App\Support\JsonCanonicalizer;

test('canonical bytes are stable across nested object insertion order', function (): void {
    $first = [
        'z' => ['b' => 2, 'a' => 1],
        'a' => ['second' => false, 'first' => null],
        'list' => [3, 2, 1],
    ];
    $second = [
        'list' => [3, 2, 1],
        'a' => ['first' => null, 'second' => false],
        'z' => ['a' => 1, 'b' => 2],
    ];
    $expected = '{"a":{"first":null,"second":false},"list":[3,2,1],"z":{"a":1,"b":2}}';

    expect(JsonCanonicalizer::encode($first))->toBe($expected)
        ->and(JsonCanonicalizer::encode($second))->toBe($expected);
});

test('canonical JSON preserves list order and emits compact unescaped UTF-8', function (): void {
    $nonAscii = "\u{00E9}";
    $encoded = JsonCanonicalizer::encode([
        'url' => 'https://capstan.test/a/b',
        'value' => $nonAscii,
        'list' => ['second', 'first'],
    ]);

    expect($encoded)->toBe('{"list":["second","first"],"url":"https://capstan.test/a/b","value":"'.$nonAscii.'"}')
        ->not->toContain('\\/')
        ->not->toContain('\\u')
        ->not->toContain("\n")
        ->not->toContain(': ');
});

test('object keys sort by UTF-16 code units rather than UTF-8 bytes', function (): void {
    $astral = "\u{10000}";
    $replacement = "\u{FFFD}";

    expect(JsonCanonicalizer::encode([
        $replacement => 2,
        $astral => 1,
    ]))->toBe('{"'.$astral.'":1,"'.$replacement.'":2}');
});

test('JSON objects and arrays remain distinct including empty and numeric-keyed values', function (): void {
    $numericObject = new stdClass;
    $numericObject->{'0'} = 'x';

    expect(JsonCanonicalizer::encode(['body' => $numericObject]))
        ->not->toBe(JsonCanonicalizer::encode(['body' => ['x']]))
        ->and(JsonCanonicalizer::encode(['body' => new stdClass]))->toBe('{"body":{}}')
        ->and(JsonCanonicalizer::encode(['body' => []]))->toBe('{"body":[]}')
        ->and(JsonCanonicalizer::encode(['body' => ['1' => 'y', '0' => 'x']]))
        ->toBe('{"body":{"0":"x","1":"y"}}');
});

test('floating-point values are rejected at every nesting depth', function (): void {
    expect(fn (): string => JsonCanonicalizer::encode([
        'outer' => ['list' => [1, 1.5]],
    ]))->toThrow(InvalidArgumentException::class);
});
