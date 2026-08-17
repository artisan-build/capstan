<?php

namespace App\Features;

class Postmaster
{
    public function resolve(): bool
    {
        return (bool) config('capstan.features.postmaster');
    }
}
