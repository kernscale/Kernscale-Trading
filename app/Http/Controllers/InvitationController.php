<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invite = Invitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();

        return Inertia::render('auth/register', ['token' => $token, 'email' => $invite->email, 'passwordRules' => Password::defaults()->toPasswordRulesString()]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'password' => ['required', 'confirmed', Password::defaults()]]);
        $user = DB::transaction(function () use ($data, $token) {
            $invite = Invitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_if($invite->accepted_at || $invite->expires_at->isPast() || strtolower($data['email']) !== $invite->email, 403, 'Invitation is invalid or expired.');
            abort_if(User::where('email', $invite->email)->exists(), 409, 'An account already exists.');
            $user = User::create(['name' => $data['name'], 'email' => $invite->email, 'password' => $data['password']]);
            $user->forceFill(['membership_status' => 'approved', 'email_verified_at' => now()])->save();
            $user->assignRole($invite->role);
            $invite->update(['accepted_at' => now()]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('security.edit');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $data = $request->validate(['email' => 'required|email|max:255|unique:users,email', 'role' => 'required|in:owner,researcher,member']);
        Invitation::issue($data['email'], $data['role'], $request->user());

        return back()->with('status', 'Invitation sent.');
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        return app(MemberManagementController::class)->revoke($request, $user);
    }
}
