<?php

namespace App\Enums;

enum WorkExpirationCause: string
{
    case DependencyUnmet = 'dependency_unmet';
    case Unclaimed = 'unclaimed';
    case NoEligibleMachine = 'no_eligible_machine';
    case Preempted = 'preempted';
}
