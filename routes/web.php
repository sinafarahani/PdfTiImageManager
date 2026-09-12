<?php

use Illuminate\Support\Facades\Route;

/*
 * The status page is open to anyone who can reach the panel: people want to see how the conversion is
 * going without an account. Starting and stopping is not open - the component authorises every action
 * against the start-action gate, so hiding the buttons from a visitor is presentation, not the guard.
 */
Route::get('/', function () {
    return view('status');
})->name('status');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
