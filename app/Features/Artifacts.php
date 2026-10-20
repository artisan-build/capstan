<?php

namespace App\Features;

use App\Support\ArtifactRenderOrigin;

class Artifacts
{
    public function __construct(private readonly ArtifactRenderOrigin $renderOrigin) {}

    public function resolve(): bool
    {
        return $this->renderOrigin->isConfigured();
    }
}
