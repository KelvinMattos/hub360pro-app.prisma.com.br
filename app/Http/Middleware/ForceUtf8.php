<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Only force UTF-8 for HTML responses
        $contentType = $response->headers->get('Content-Type');
        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }
        
        return $response;
    }
}