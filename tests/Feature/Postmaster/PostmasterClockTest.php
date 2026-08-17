<?php

use App\Enums\MessageType;
use App\Models\Envelope;
use App\Providers\AppServiceProvider;
use App\Support\EnvelopeSigner;
use App\Support\PostmasterClock;

const CLOCK_SERVER_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function (): void {
    config([
        'capstan.postmaster.server_id' => CLOCK_SERVER_ID,
        'capstan.postmaster.signing_key' => 'clock-test-signing-key',
    ]);
});

function clockEnvelope(): Envelope
{
    $envelope = new Envelope([
        'id' => CLOCK_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAA',
        'type' => MessageType::Generic,
        'from_address' => 'sender@'.CLOCK_SERVER_ID,
        'to_address' => 'receiver@'.CLOCK_SERVER_ID,
        'body' => (object) ['subject' => 'Clock'],
        'refs' => [],
        'message_id' => CLOCK_SERVER_ID.':01ARZ3NDEKTSV4RRFFQ69G5FAB',
    ]);
    $envelope->created_at = now()->utc()->startOfSecond();

    return $envelope;
}

test('utc is accepted', function (): void {
    config(['app.timezone' => 'UTC']);

    expect(fn () => PostmasterClock::assertUtc())->not->toThrow(RuntimeException::class);
    $envelope = clockEnvelope();
    $envelope->signature = app(EnvelopeSigner::class)->sign($envelope);

    expect(app(EnvelopeSigner::class)->verify($envelope))->toBeTrue();
});

test('signing and verifying fail loudly when the application timezone is not utc', function (): void {
    $envelope = clockEnvelope();
    $envelope->signature = app(EnvelopeSigner::class)->sign($envelope);

    config(['app.timezone' => 'America/New_York']);

    expect(fn () => app(EnvelopeSigner::class)->sign($envelope))->toThrow(RuntimeException::class)
        ->and(fn () => app(EnvelopeSigner::class)->verify($envelope))->toThrow(RuntimeException::class);
});

test('booting with the postmaster feature enabled and a non utc timezone fails early', function (): void {
    config(['capstan.features.postmaster' => true, 'app.timezone' => 'Europe/Paris']);

    expect(fn () => (new AppServiceProvider(app()))->boot())->toThrow(RuntimeException::class);

    config(['app.timezone' => 'UTC']);
    expect(fn () => (new AppServiceProvider(app()))->boot())->not->toThrow(RuntimeException::class);

    config(['capstan.features.postmaster' => false, 'app.timezone' => 'Europe/Paris']);
    expect(fn () => (new AppServiceProvider(app()))->boot())->not->toThrow(RuntimeException::class);
});
