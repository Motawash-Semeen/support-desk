<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0f172a">

<title>{{ $pageTitle ? $pageTitle.' · ' : '' }}{{ config('app.name', 'Support Desk') }}</title>

<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
