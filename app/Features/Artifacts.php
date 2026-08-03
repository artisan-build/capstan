<?php

namespace App\Features;

class Artifacts
{
    public function resolve(): bool
    {
        return (bool) config('capstan.features.artifacts');
    }
}
