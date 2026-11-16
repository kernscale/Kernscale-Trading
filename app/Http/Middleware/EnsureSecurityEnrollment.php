<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecurityEnrollment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasEnabledTwoFactorAuthentication() || ! $request->user()->passkeys()->exists()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Enroll an authenticator app and a passkey before continuing.'], 403)
                : redirect()->route('security.edit');
        }

        return $next($request);
    }
}
