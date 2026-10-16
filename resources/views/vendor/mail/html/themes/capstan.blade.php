@inject('mailBrand', \App\Support\AppBrand::class)
@php($accent = $mailBrand->current()->accentLight)
@include('mail::themes.default')

.logo {
    height: 48px;
    max-height: 48px;
    width: auto;
}
@if ($accent !== null)

a,
.header a {
    color: {{ $accent }};
}

.button-blue,
.button-primary {
    background-color: {{ $accent }};
    border-bottom: 8px solid {{ $accent }};
    border-left: 18px solid {{ $accent }};
    border-right: 18px solid {{ $accent }};
    border-top: 8px solid {{ $accent }};
    color: {{ \App\Support\AppBrandValues::foregroundFor($accent) }};
}
@endif
