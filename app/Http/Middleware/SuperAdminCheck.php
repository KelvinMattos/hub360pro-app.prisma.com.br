<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        // Se der erro aqui, o sistema trava. 
        // Vamos garantir que Auth está importado lá em cima.
        $user = Auth::user();
        
        // Lista de e-mails permitidos
        $allowedEmails = ['admin@prismaads.com.br'];

        if ($user && ($user->id === 1 || in_array($user->email, $allowedEmails))) {
            return $next($request);
        }

        return redirect()->route('dashboard')->with('error', 'Acesso negado.');
    }
}