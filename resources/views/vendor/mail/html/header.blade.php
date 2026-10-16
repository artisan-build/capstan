@props(['url'])
@inject('mailBrand', \App\Support\AppBrand::class)
@php($brand = $mailBrand->current())
<tr>
<td class="header" data-testid="mail-brand-header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brand->mailLogoUrl() !== null)
<img src="{{ $brand->mailLogoUrl() }}" class="logo" alt="{{ $brand->displayName }}">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
