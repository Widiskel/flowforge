<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseJwtQueryToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Authorization') && $request->filled('token')) {
            $request->headers->set('Authorization', 'Bearer '.$request->query('token'));
        }

        return $next($request);
    }
}
