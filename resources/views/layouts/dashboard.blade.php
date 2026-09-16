<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Dashboard') · {{ config('app.name', 'Applyr') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center gap-8 px-4 py-4">
                <a href="{{ route('dashboard') }}" class="text-lg font-semibold">{{ config('app.name', 'Applyr') }}</a>

                <nav class="flex gap-6 text-sm font-medium text-gray-600">
                    <a href="#" class="hover:text-gray-900">Applications</a>
                    <a href="#" class="hover:text-gray-900">SearchProfiles</a>
                    <a href="#" class="hover:text-gray-900">MasterProfile</a>
                    <a href="#" class="hover:text-gray-900">Adapters</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            @yield('content')
        </main>
    </body>
</html>
