<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php($appName = config('app.name', 'Streakly'))
@php($description = 'Streakly is a self-hosted, GitHub-style habit and activity tracker. Log daily activities, build streaks, and watch your year-long contribution heatmap grow — now with AI-powered insights.')

<title>
    {{ filled($title ?? null) ? $title.' - '.$appName : $appName }}
</title>

<meta name="description" content="{{ $description }}" />
<meta name="theme-color" content="#f97316" />
<meta name="author" content="Hemant Kumawat" />

{{-- Open Graph --}}
<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ $appName }}" />
<meta property="og:title" content="{{ filled($title ?? null) ? $title : $appName }}" />
<meta property="og:description" content="{{ $description }}" />
<meta property="og:url" content="{{ url()->current() }}" />

{{-- Twitter --}}
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="{{ filled($title ?? null) ? $title : $appName }}" />
<meta name="twitter:description" content="{{ $description }}" />

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
