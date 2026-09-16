<?php

namespace App\Console\Commands;

use App\Postmaster\RetireDeadSpokes;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;

final class RetireDeadSpokesCommand extends SystemAuthorityCommand
{
    protected $signature = 'postmaster:retire-dead-spokes
        {--local : Run against the local database, zero Cloud dependency}';

    protected $description = 'Retire Postmaster spokes backed by dead credentials';

    public function handle(RetireDeadSpokes $retire): int
    {
        if (! $this->option('local')) {
            $this->error('This state-changing command is local-only; pass --local.');

            return self::FAILURE;
        }

        $count = $retire();
        $this->line("Retired {$count} dead Postmaster spoke(s).");

        return self::SUCCESS;
    }
}
