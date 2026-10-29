<?php

namespace App\View\Components;

use App\Support\AppBrandValues;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\View\Layout;

// Use the package view without re-entering the configured BrandedBuiltForCloudLayout.
class SidebarShell extends Layout
{
    public function __construct(
        public AppBrandValues $brand,
        ?string $title = null,
        ?User $user = null,
        ?string $stylesheetUrl = null,
        ?string $wordmarkUrl = null,
        ?string $logoutUrl = null,
    ) {
        parent::__construct($title ?? $brand->displayName);

        $this->user = $user;
        $this->stylesheetUrl = $stylesheetUrl ?? $this->stylesheetUrl;
        $this->wordmarkUrl = $wordmarkUrl ?? $this->wordmarkUrl;
        $this->logoutUrl = $logoutUrl ?? $this->logoutUrl;
    }
}
