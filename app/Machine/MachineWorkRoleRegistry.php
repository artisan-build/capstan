<?php

namespace App\Machine;

use App\Models\WorkItem;

final class MachineWorkRoleRegistry
{
    /**
     * These are the server-owned roles offered by a default machine profile.
     * Human work is deliberately absent: it always requires a person.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return [
            'builder',
            'code_review',
            WorkItem::ROLE_ORCHESTRATOR,
            'reviewer',
            'security_review',
        ];
    }
}
