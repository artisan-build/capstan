<x-app-shell
    :title="$title"
    :stylesheet-url="$stylesheetUrl"
    :wordmark-url="$wordmarkUrl"
    :user="$user"
    :logout-url="$logoutUrl"
>
    {{ $slot }}
</x-app-shell>
