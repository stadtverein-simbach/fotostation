<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif Fotostation · {{ config('fotostation.operator.name') }}</title>
    <meta name="robots" content="@yield('robots', 'index,follow')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
            <a href="{{ route('home') }}" class="font-semibold">Fotostation</a>
            <span class="text-sm text-stone-500">{{ config('fotostation.operator.name') }}</span>
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-6">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mx-auto max-w-4xl px-4 py-8 text-sm text-stone-500">
        <nav class="flex flex-wrap gap-4">
            <a class="underline" href="{{ route('pages.impressum') }}">Impressum</a>
            <a class="underline" href="{{ route('pages.datenschutz') }}">Datenschutz</a>
            <a class="underline" href="{{ route('pages.terms') }}">Nutzungsbedingungen</a>
        </nav>
    </footer>
</body>
</html>
