<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trava reversível de rota: `->middleware('feature:orders')`. Com a flag
 * desligada (padrão de `config/features.php`), a rota devolve 404 — não
 * redireciona nem mostra mensagem, se comporta como se a rota não existisse.
 * Não apaga dado nem tabela; é só a rota (e, no menu, o item correspondente)
 * que fica invisível.
 */
class FeatureFlag
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        abort_unless((bool) config("features.$flag", false), 404);

        return $next($request);
    }
}
