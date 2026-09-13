<?php

use Illuminate\Support\Facades\Route;

/*
 * The panel is open to anyone who can reach it: people want to see how the conversion is going
 * without an account. Starting and stopping is not open - the component authorises every action
 * against the start-action gate, so hiding the buttons from a visitor is presentation, not the guard.
 */
Route::get('/', function () {
    return view('status');
})->name('status');

Route::get('/failures', function () {
    return view('failures');
})->name('failures');

/*
 * There is no separate dashboard any more: it showed exactly what the front page shows. The name
 * stays because it is where Fortify sends somebody after they sign in (config/fortify.php).
 */
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::redirect('/dashboard', '/')->name('dashboard');
});
