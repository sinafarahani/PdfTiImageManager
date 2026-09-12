<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="bg-gray-100 font-sans antialiased">
        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-xl font-semibold text-gray-900">{{ config('app.name') }}</h1>

                {{-- Anybody may watch; only an administrator can start or stop, and that is checked again
                     in the component itself, not only hidden here. --}}
                @guest
                    <a href="{{ route('login') }}" class="text-sm text-blue-700 hover:text-blue-800">{{ __('Sign in') }}</a>
                @else
                    <a href="{{ route('dashboard') }}" class="text-sm text-blue-700 hover:text-blue-800">{{ __('Dashboard') }}</a>
                @endguest
            </div>

            <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
                <livewire:action />
            </div>
        </div>

        @livewireScripts
    </body>
</html>
