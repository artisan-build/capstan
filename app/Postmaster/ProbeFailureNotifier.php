<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Models\SpokeProbe;

interface ProbeFailureNotifier
{
    /**
     * Notify through infrastructure independent of the failed spoke's poll channel.
     */
    public function notify(Spoke $spoke, SpokeProbe $probe): void;
}
