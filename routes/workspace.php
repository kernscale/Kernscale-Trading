<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'approved', 'security.enrolled'])->group(function () {
    Route::get('/research/sessions/{session}/workspace', [WorkspaceController::class, 'context'])->name('workspace.context');
    Route::patch('/research/sessions/{session}/thesis', [WorkspaceController::class, 'thesis'])->name('workspace.thesis');
    Route::post('/research/proposals/{proposal}/decision', [WorkspaceController::class, 'decision'])->name('workspace.decision');
});
