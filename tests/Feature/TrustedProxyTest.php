<?php

test('a forged forwarded host cannot control generated urls', function (): void {
    config(['app.url' => 'https://app.test']);

    $response = $this->withHeaders([
        'X-Forwarded-Host' => 'attacker.example',
    ])->get('https://app.test/dashboard')->assertRedirect();

    expect($response->headers->get('Location'))->toBe('https://app.test/bfc/login?intended=%2Fdashboard');
});
