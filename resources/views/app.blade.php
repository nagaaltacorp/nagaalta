<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Admin Dashboard') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('assets/side-nav-logo/Gemini_Generated_Image_7wme0a7wme0a7wme-removebg-preview.png') }}">
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>