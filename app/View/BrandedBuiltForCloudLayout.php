<?php

namespace App\View;

use App\Support\AppBrand;
use App\Support\AppBrandValues;
use ArtisanBuild\BuiltForCloud\View\Layout;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;

/**
 * Routes every Built for Cloud page through Capstan's shared application shell.
 * The shared shell supplies Scalpels chrome, signed-in navigation and the
 * currently applied brand without changing the package's views.
 */
class BrandedBuiltForCloudLayout extends Layout
{
    public AppBrandValues $brand;

    public function __construct(?string $title = null)
    {
        $this->brand = App::make(AppBrand::class)->current();

        parent::__construct($title ?? $this->brand->displayName);
    }

    public function render(): ViewContract
    {
        return View::make('layouts.built-for-cloud');
    }
}
