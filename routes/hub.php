<?php

use App\Http\Controllers\CommunityController;
use App\Http\Controllers\HubController;
use App\Http\Controllers\OutputController;
use App\Http\Controllers\PortfolioToolsController;
use App\Http\Controllers\ResearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'approved', 'security.enrolled'])->group(function () {
    Route::get('/dashboard', [HubController::class, 'dashboard'])->name('dashboard');
    Route::post('/portfolio/watchlists', [PortfolioToolsController::class, 'add']);
    Route::delete('/portfolio/watchlists/{id}', [PortfolioToolsController::class, 'remove']);
    Route::get('/portfolio/export', [PortfolioToolsController::class, 'export']);
    Route::get('/portfolio', [HubController::class, 'portfolio'])->name('portfolio');
    Route::get('/research', [ResearchController::class, 'index'])->name('research');
    Route::patch('/research/sessions/{session}', [ResearchController::class, 'update']);
    Route::post('/research/sessions', [ResearchController::class, 'create']);
    Route::post('/research/sessions/{session}/runs', [ResearchController::class, 'run']);
    Route::get('/research/runs/{run}/events', [ResearchController::class, 'events']);
    Route::post('/research/runs/{run}/cancel', [ResearchController::class, 'cancel']);
    Route::post('/research/sessions/{session}/board', [ResearchController::class, 'card']);
    Route::patch('/research/sessions/{session}/board/{card}', [ResearchController::class, 'updateCard']);
    Route::get('/outputs', [OutputController::class, 'index'])->name('outputs');
    Route::post('/outputs', [OutputController::class, 'create']);
    Route::patch('/outputs/{output}', [OutputController::class, 'update']);
    Route::post('/outputs/{output}/submit', [OutputController::class, 'submit']);
    Route::post('/outputs/{output}/review', [OutputController::class, 'review']);
    Route::get('/community', [CommunityController::class, 'index'])->name('community');
    Route::post('/community', [CommunityController::class, 'create']);
    Route::post('/community/{topic}/comments', [CommunityController::class, 'comment']);
    Route::patch('/community/{topic}', [CommunityController::class, 'lock']);
    Route::get('/admin', [HubController::class, 'admin'])->name('admin');
});
