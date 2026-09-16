<?php

use ArtisanBuild\BuiltForCloud\Testing\ThinHostConformance;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('reports Capstan as the product name', function (): void {
    $this->getJson('/bfc/meta')->assertOk()->assertJsonPath('product', 'Capstan');
});

it('is a thin host with package-owned human authentication', function (): void {
    $auth = config('auth');

    expect($auth)->toBeArray()
        ->and(ThinHostConformance::sourceArtifacts(base_path()))->toBe([])
        ->and(ThinHostConformance::configurationArtifacts($auth))->toBe([]);
});

it('detects retired host auth source and configuration with positive controls', function (): void {
    $root = sys_get_temp_dir().'/capstan-thin-host-'.bin2hex(random_bytes(8));

    try {
        File::ensureDirectoryExists($root.'/app/Models');
        File::ensureDirectoryExists($root.'/app/Http/Controllers/Auth');
        File::ensureDirectoryExists($root.'/app/Console/Commands');
        File::ensureDirectoryExists($root.'/database/migrations');
        File::ensureDirectoryExists($root.'/resources/views/auth');
        File::put($root.'/app/Models/User.php', '<?php');
        File::put($root.'/app/Http/Controllers/Auth/LoginController.php', '<?php');
        File::put($root.'/app/Console/Commands/IssueToken.php', '<?php');
        File::put($root.'/database/migrations/2026_09_16_000000_create_users_table.php', '<?php');
        File::put($root.'/resources/views/auth/login.blade.php', 'retired auth UI');

        expect(ThinHostConformance::sourceArtifacts($root))->toBe([
            'app/Console/Commands/IssueToken.php' => 'token-command',
            'app/Http/Controllers/Auth/LoginController.php' => 'auth-controller',
            'app/Models/User.php' => 'app-user-model',
            'database/migrations/2026_09_16_000000_create_users_table.php' => 'users-migration',
            'resources/views/auth/login.blade.php' => 'copied-auth-ui',
        ])->and(ThinHostConformance::configurationArtifacts([
            'defaults' => ['guard' => 'host'],
            'guards' => ['host' => ['driver' => 'token', 'provider' => 'host']],
            'providers' => ['users' => ['driver' => 'database', 'table' => 'users']],
        ]))->toBe([
            'human-provider',
            'human-guard',
            'custom-guard:host',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('fresh migrations use package auth tables without retired host columns', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('invitations'))->toBeTrue()
        ->and(Schema::hasTable('credential_authorizations'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'role'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
        ->and(Schema::hasColumns('invitations', ['id', 'token', 'role']))->toBeTrue()
        ->and(Schema::hasColumn('invitations', 'code'))->toBeFalse();
});
