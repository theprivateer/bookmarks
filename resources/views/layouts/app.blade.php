<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Bookmarks') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 antialiased">
    <flux:sidebar sticky collapsible="mobile" class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.header>
            <flux:brand href="{{ route('home') }}" name="Bookmarks">
                <x-slot name="logo">
                    <flux:icon name="bookmark-square" variant="solid" class="size-6" />
                </x-slot>
            </flux:brand>
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.item icon="magnifying-glass" href="{{ route('home') }}" :current="request()->routeIs('home')">Search</flux:sidebar.item>
            <flux:sidebar.item icon="chat-bubble-left-right" href="{{ route('chat') }}" :current="request()->routeIs('chat')">Chat</flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:sidebar.nav>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:sidebar.item icon="arrow-right-start-on-rectangle" type="submit">Sign out</flux:sidebar.item>
            </form>
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="subtle" icon="arrow-right-start-on-rectangle" size="sm">Sign out</flux:button>
        </form>
    </flux:header>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
