<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:6,1'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});
Route::middleware(['auth', 'approved', 'verified', 'security.enrolled', 'password.confirm'])->group(function () {
    Route::post('/admin/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('/admin/members/{user}', [InvitationController::class, 'revoke'])->name('members.revoke');
});
