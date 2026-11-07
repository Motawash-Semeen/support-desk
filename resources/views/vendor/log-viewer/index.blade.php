{{--
    Override of opcodesio/log-viewer's page (resources/views/index.blade.php, v3).
    Only change: both inline <script> blocks carry the per-request CSP nonce
    (SecurityHeaders allows no un-nonced inline script), and the inline
    onerror="alert()" handlers are dropped because the CSP blocks them anyway.
    Re-check this file when upgrading the package.
--}}
@php($nonce = \Illuminate\Support\Facades\Vite::cspNonce())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @if ($assetsPublished)
        <link rel="shortcut icon" href="{{ asset(mix('img/log-viewer-32.png', config('log-viewer.assets_path'))) }}">
    @else
        {!! \Opcodes\LogViewer\Facades\LogViewer::favicon() !!}
    @endif

    <title>Log Viewer{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>

    @if ($assetsPublished)
        <link href="{{ asset(mix('app.css', config('log-viewer.assets_path'))) }}" rel="stylesheet">
    @else
        {!! \Opcodes\LogViewer\Facades\LogViewer::css() !!}
    @endif
</head>

<body class="h-full px-3 lg:px-5 bg-gray-100 dark:bg-gray-900">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full">
    <router-view></router-view>
</div>

<script nonce="{{ $nonce }}">
    window.LogViewer = @json($logViewerScriptVariables);
</script>
@if ($assetsPublished)
    <script src="{{ asset(mix('app.js', config('log-viewer.assets_path'))) }}" nonce="{{ $nonce }}"></script>
@else
    {!! \Illuminate\Support\Str::replaceFirst('<script>', '<script nonce="'.e($nonce).'">', (string) \Opcodes\LogViewer\Facades\LogViewer::js()) !!}
@endif
</body>
</html>
