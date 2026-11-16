<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as Contract;

class PasskeyLoginResponse implements Contract
{
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user->hasEnabledTwoFactorAuthentication()) {
            Auth::logout();
            $request->session()->forget('security.totp_user');
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', false);
            $url = route('two-factor.login');
        } else {
            $url = route('security.edit');
        }

        return $request->wantsJson() ? response()->json(['redirect' => $url]) : redirect($url);
    }
}
