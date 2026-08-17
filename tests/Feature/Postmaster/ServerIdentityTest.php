<?php

use App\Support\ServerIdentity;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['capstan.postmaster.server_id' => null]);
    app()->forgetInstance(ServerIdentity::class);
});

test('server identity is minted once and memoized through the container', function (): void {
    $resolver = app(ServerIdentity::class);
    $first = $resolver->id();
    $second = app(ServerIdentity::class)->id();

    expect($first)->toBe($second)
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}\z/')
        ->and(app(ServerIdentity::class))->toBe($resolver)
        ->and(DB::table('server_identity')->count())->toBe(1)
        ->and(DB::table('server_identity')->value('server_id'))->toBe($first);
});

test('a concurrent mint losing the singleton insert race rereads the winner', function (): void {
    $winner = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $now = now();

    DB::table('server_identity')->insert([
        'id' => 1,
        'server_id' => $winner,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $resolver = new class extends ServerIdentity
    {
        private int $reads = 0;

        protected function existingId(): ?string
        {
            $this->reads++;

            return $this->reads === 1 ? null : parent::existingId();
        }
    };

    expect($resolver->id())->toBe($winner)
        ->and(DB::table('server_identity')->count())->toBe(1);
});

test('a valid configured identity overrides stored state', function (): void {
    $stored = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $override = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
    $now = now();

    DB::table('server_identity')->insert([
        'id' => 1,
        'server_id' => $stored,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    config(['capstan.postmaster.server_id' => $override]);

    expect((new ServerIdentity)->id())->toBe($override)
        ->and(DB::table('server_identity')->value('server_id'))->toBe($stored);
});

test('malformed configured identities fail closed', function (string $serverId): void {
    config(['capstan.postmaster.server_id' => $serverId]);

    expect(fn (): string => (new ServerIdentity)->id())->toThrow(InvalidArgumentException::class);
})->with([
    'hostname' => 'capstan.example',
    'lowercase ULID' => '01arz3ndektsv4rrffq69g5fav',
    'too short' => '01ARZ3NDEKTSV4RRFFQ69G5FA',
    'forbidden Crockford character' => '01ARZ3NDEKTSV4RRFFQ69G5FAI',
    'surrounding whitespace' => ' 01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'trailing newline' => "01ARZ3NDEKTSV4RRFFQ69G5FAV\n",
]);
