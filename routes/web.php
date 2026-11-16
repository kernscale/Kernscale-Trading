<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
require __DIR__.'/settings.php';
require __DIR__.'/membership.php';
require __DIR__.'/hub.php';

Route::get('/ready', ReadinessController::class);

require __DIR__.'/workspace.php';
