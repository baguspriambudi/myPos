<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
| Pengguna POS tidak mendaftar sendiri dan tidak punya email. Akun dibuat
| OWNER/SUPER ADMIN lewat master Pengguna, dan kata sandi yang terlupa direset
| dari sana pula. Karena itu route register, forgot-password, reset-password,
| dan verifikasi email dari starter kit sengaja tidak dipasang.
|
| Controller bawaannya sengaja tidak dihapus supaya mudah dihidupkan kembali
| bila kelak dibutuhkan, tetapi tanpa route di bawah ini semuanya tidak dapat
| diakses.
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
