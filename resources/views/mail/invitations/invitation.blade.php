<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('You\'re invited to join :app', ['app' => config('app.name')]) }}</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #18181b;">
    <h1>{{ __('You\'re invited to join :app', ['app' => config('app.name')]) }}</h1>

    <p>{{ __('Use this invitation link to create your account:') }}</p>

    <p><a href="{{ $inviteUrl }}">{{ $inviteUrl }}</a></p>

    <p>{{ __('Role: :role', ['role' => $role]) }}</p>
    <p>{{ __('Expires: :date', ['date' => $expiresAt]) }}</p>
</body>
</html>
