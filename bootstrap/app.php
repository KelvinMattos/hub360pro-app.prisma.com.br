<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        
        // 1. Força UTF-8 em todas as respostas (Correção de caracteres)
        $middleware->append(\App\Http\Middleware\ForceUtf8::class);
        
        // 2. Apelido para o Middleware do Super Admin (Segurança)
        $middleware->alias([
            'super.admin' => \App\Http\Middleware\SuperAdminCheck::class,
        ]);

        // 3. Libera as rotas de webhook da verificação CSRF (Para notificações do ML/Shopee/Asaas)
        $middleware->validateCsrfTokens(except: [
            'webhook/*', 
            'api/webhook/*',
        ]);

        // 4. Correção para SSL/HTTPS em ambientes com Proxy (cPanel/Nginx)
        $middleware->trustProxies(at: '*');

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
