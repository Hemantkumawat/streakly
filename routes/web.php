<?php

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::tracker')->name('dashboard');
    Route::get('export', ExportController::class)->name('tracker.export');
});

require __DIR__.'/settings.php';
