<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <!-- VERIFICATION_MARKER_12345 -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'PrismaHUB') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

        <!-- SheetJS (leitura/escrita de planilhas .xlsx/.csv no cliente — módulo Cálculo Promo) -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

        <!-- Scripts -->
        @routes
        <script>
            if (typeof Ziggy !== 'undefined') {
                window.Ziggy = Ziggy;
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
        
        <script>
            // Capturar falhas críticas no mount do Inertia
            window.addEventListener('error', function(e) {
                if (e.message.includes('Inertia') || e.message.includes('mount')) {
                    console.error('Falha crítica na interface:', e);
                }
            });
        </script>
    </head>
    <body class="font-sans antialiased bg-[#0F172A] text-slate-300">
        @inertia
    </body>
</html>
