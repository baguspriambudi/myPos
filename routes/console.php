<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Period tidak boleh tertinggal di status CLOSING selamanya (Addendum 44, 57.5).
 * Prosesnya sendiri sudah memulihkan diri saat gagal; jadwal ini hanya untuk
 * kasus proses mati mendadak, ketika kode pemulihannya ikut mati.
 */
Schedule::command('periode:pulihkan-closing')->everyFiveMinutes()->withoutOverlapping();
