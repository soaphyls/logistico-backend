<?php

namespace App\Http\Middleware;

use App\Models\MenuItem;
use App\Services\MenuVisibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $route = '/' . ltrim($request->getPathInfo(), '/');

        $menuExists = MenuItem::where('route', $route)->exists();
        if (!$menuExists) {
            return $next($request);
        }

        $service = app(MenuVisibilityService::class);
        if (!$service->canAccessRoute($user, $route)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this page.',
            ], 403);
        }

        return $next($request);
    }
}
