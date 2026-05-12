<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandlePutPatchViaPost
{
    /**
     * Handle an incoming request.
     *
     * Reads the _method field from POST requests (including JSON bodies)
     * and overrides the HTTP method so Laravel routes it correctly.
     * This is required for shared hosting environments that block PUT/PATCH/DELETE.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->has('_method')) {
            $method = strtoupper($request->input('_method'));

            if (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                $request->setMethod($method);
            }
        }

        return $next($request);
    }
}
