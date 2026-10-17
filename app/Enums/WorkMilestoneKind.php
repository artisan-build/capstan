<?php

namespace App\Enums;

enum WorkMilestoneKind: string
{
    case Started = 'started';
    case Blocked = 'blocked';
    case PullRequestOpened = 'pr_opened';
    case Done = 'done';
    case Failed = 'failed';
    case Released = 'released';
    case Note = 'note';
}
