<?php

namespace App\Features;

use App\Support\ArtifactRenderOrigin;

class Artifacts
{
    public function __construct(private readonly ArtifactRenderOrigin $renderOrigin) {}

    /**
     * Two independent switches, both required. The Pennant flag is the
     * operator's intent; a configured render origin is whether the promise can
     * be kept at all. Without a second, isolated host there is nowhere to serve
     * artifact HTML from (D22), so ingest must refuse rather than accept blobs
     * the install can never hand back — which is the state every Laravel Cloud
     * install sits in until a custom domain is attached.
     */
    public function resolve(): bool
    {
        return (bool) config('capstan.features.artifacts')
            && $this->renderOrigin->isConfigured();
    }
}
