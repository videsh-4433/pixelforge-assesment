<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? to_route('generations.index') : to_route('login'));
Route::redirect('/dashboard', '/generations')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/estimate', [GenerationController::class, 'estimate'])->middleware('throttle:120,1')->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');

    Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('/credits', [CreditController::class, 'store'])->middleware('throttle:10,1')->name('credits.store');

    Route::get('/admin/report', ReportController::class)->middleware('can:admin')->name('admin.report');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
