<?php

namespace App\Work;

use Closure;

final class WorkOrchestrationAuthor
{
    private static ?string $taskId = null;

    public static function current(): ?string
    {
        return self::$taskId;
    }

    /** @template T
     * @param  Closure(): T  $write
     * @return T
     */
    public static function run(string $taskId, Closure $write): mixed
    {
        $previous = self::$taskId;
        self::$taskId = $taskId;
        try {
            return $write();
        } finally {
            self::$taskId = $previous;
        }
    }
}
