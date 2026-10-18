<?php

namespace App\Http\Middleware;

use App\Models\IotDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    /**
     * Handle an incoming request from an IoT device.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->header('X-Device-Token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Missing device authentication token.',
                'code' => 'AUTH_TOKEN_MISSING',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Compute SHA-256 hash to compare with stored credential hash
        $tokenHash = hash('sha256', $token);

        $device = IotDevice::where('api_token_hash', $tokenHash)->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Invalid device authentication token.',
                'code' => 'AUTH_TOKEN_INVALID',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Attach authenticated device to request attributes
        $request->attributes->set('authenticated_device', $device);

        return $next($request);
    }
}
