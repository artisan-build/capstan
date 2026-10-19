<?php

namespace App\Machine;

use RuntimeException;

final class InstalledUsageCollectorSource
{
    public function source(): string
    {
        $source = file_get_contents(resource_path('installers/openusage-collector.php'));

        if (! is_string($source) || $source === '') {
            throw new RuntimeException('The installed usage collector source is unavailable.');
        }

        return $source;
    }
}
