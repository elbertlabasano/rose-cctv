<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#5d1616">
    <title inertia>RoseWatch</title>
    <link rel="icon" href="/brand/rose-bakeshop-mark.webp" type="image/webp">
    @if(request()->routeIs('home'))
        <link rel="preload" as="image" href="/brand/homepage-bakery.webp"
              imagesrcset="/brand/homepage-bakery-640.webp 640w, /brand/homepage-bakery-960.webp 960w, /brand/homepage-bakery.webp 1536w"
              imagesizes="(max-width: 359px) calc(100vw - 28px), (max-width: 767px) calc(100vw - 40px), (max-width: 1100px) calc((100vw - 48px) * 0.515), (max-width: 1400px) calc((100vw - 80px) * 0.515), 680px"
              fetchpriority="high">
    @endif
    @vite('resources/js/app.ts')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
