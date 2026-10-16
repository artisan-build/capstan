<?php

namespace App\View;

use App\Support\AppBrand;
use App\Support\AppBrandValues;
use ArtisanBuild\BuiltForCloud\View\Layout;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;

/**
 * Built for Cloud's page shell wearing the applied brand. Configured as
 * built-for-cloud.layout, so every <x-bfc-layout> page (sign-in, invitation,
 * password reset, members, credentials) and full-page Livewire screen gets
 * the applied name, icon and accent. Without an applied kit the shell shows
 * the configured app name and Capstan's built-in mark. The Scalpels wordmark
 * and navigation are the package's, unchanged.
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
