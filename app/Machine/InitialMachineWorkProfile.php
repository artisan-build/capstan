<?php

namespace App\Machine;

use App\Models\Team;

final readonly class InitialMachineWorkProfile
{
    public function __construct(private MachineWorkRoleRegistry $roles) {}

    /**
     * @return array{
     *     team_id: int,
     *     enabled: true,
     *     roles: list<string>,
     *     capabilities: list<string>,
     *     role_providers: array<string, string>,
     *     concurrency_ceiling: 1
     * }
     */
    public function default(): array
    {
        return [
            'team_id' => Team::default()->id,
            'enabled' => true,
            'roles' => $this->roles->all(),
            'capabilities' => [],
            'role_providers' => [],
            'concurrency_ceiling' => 1,
        ];
    }
}
