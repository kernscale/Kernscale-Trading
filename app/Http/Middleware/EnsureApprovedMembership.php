<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $user->refresh();
            if ($user->membership_status !== 'approved') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(403, 'Membership approval is required.');
            }
            if ($user->hasEnabledTwoFactorAuthentication() && $request->session()->get('security.totp_user') !== $user->id && ! $request->routeIs('logout')) {
                Auth::logout();
                $request->session()->regenerate();
                $request->session()->put('login.id', $user->id);
                $request->session()->put('login.remember', false);

                return $request->expectsJson()
                    ? response()->json(['redirect' => route('two-factor.login')], 401)
                    : redirect()->route('two-factor.login');
            }
            abort_if($request->routeIs('two-factor.disable'), 403, 'Authenticator enrollment is required.');
            if ($request->routeIs('passkey.destroy')) {
                return DB::transaction(function () use ($request, $next, $user) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    abort_if($user->passkeys()->count() <= 1, 403, 'Keep at least one enrolled passkey.');

                    return $next($request);
                });
            }
        }

        return $next($request);
    }
}
