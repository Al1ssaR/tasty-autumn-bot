<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Operator\StatisticsController;
use App\Http\Controllers\Operator\TicketCloseController;
use App\Http\Controllers\Operator\TicketController;
use App\Http\Controllers\Operator\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'operator.active'])->group(function (): void {
    Route::redirect('/', '/tickets');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->name('tickets.replies.store');
    Route::post('/tickets/{ticket}/close', TicketCloseController::class)
        ->name('tickets.close');

    Route::get('/statistics', StatisticsController::class)->name('statistics.index');
});
