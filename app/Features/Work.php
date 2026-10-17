<?php

namespace App\Features;

class Work
{
    public function resolve(): bool
    {
        return (bool) config('capstan.features.work');
    }
}
