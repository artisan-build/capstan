<?php

use App\Support\Address;

test('an address round trips exactly and compares its server id case sensitively', function (): void {
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $address = 'inbox.primary-1@'.$serverId;
    $parsed = Address::parse($address);

    expect($parsed->localPart)->toBe('inbox.primary-1')
        ->and($parsed->serverId)->toBe($serverId)
        ->and($parsed->format())->toBe($address)
        ->and((string) $parsed)->toBe($address)
        ->and($parsed->isLocal($serverId))->toBeTrue()
        ->and($parsed->isLocal('01ARZ3NDEKTSV4RRFFQ69G5FAW'))->toBeFalse();
});

test('make applies the same locked grammar as parse', function (): void {
    $address = Address::make('kb_pool', '01ARZ3NDEKTSV4RRFFQ69G5FAV');

    expect($address->format())->toBe('kb_pool@01ARZ3NDEKTSV4RRFFQ69G5FAV');
});

test('malformed addresses are rejected without normalization', function (string $address): void {
    expect(fn (): Address => Address::parse($address))->toThrow(InvalidArgumentException::class);
})->with([
    'no separator' => 'inbox',
    'two separators' => 'inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV@extra',
    'empty local part' => '@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'empty server part' => 'inbox@',
    'uppercase local part' => 'Inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'local part too long' => str_repeat('a', 65).'@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'non ULID server' => 'inbox@capstan.example',
    'leading dot' => '.inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'trailing dot' => 'inbox.@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'leading dash' => '-inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'trailing dash' => 'inbox-@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'leading underscore' => '_inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'trailing underscore' => 'inbox_@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'leading whitespace' => ' inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'trailing whitespace' => 'inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV ',
    'newline in local part' => "inbox\n@01ARZ3NDEKTSV4RRFFQ69G5FAV",
    'newline after server' => "inbox@01ARZ3NDEKTSV4RRFFQ69G5FAV\n",
    'lowercase server' => 'inbox@01arz3ndektsv4rrffq69g5fav',
]);
