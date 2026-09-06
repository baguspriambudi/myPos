<?php

namespace App\Console\Commands;

use App\Models\ClosingAttemptLog;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mengembalikan period yang tertinggal di status CLOSING (Addendum 44, 57.5).
 *
 * Proses penutupan mengembalikan statusnya sendiri saat gagal. Yang tidak bisa
 * ia tangani adalah kematian mendadak — server dimatikan, PHP kehabisan waktu,
 * koneksi database putus di tengah — karena kode pemulihannya ikut mati.
 * Period lalu terkunci selamanya: transaksi baru ditolak, dan tidak ada yang
 * bisa menutup maupun membukanya.
 *
 * Perintah ini menjadi jaring pengamannya. Dijalankan berkala; period yang
 * CLOSING lebih dari 15 menit tanpa langkah baru dianggap tertinggal dan
 * dikembalikan ke status sebelum penutupan dimulai.
 */
class PulihkanClosingTertinggal extends Command
{
    /** Batas diam sebelum sebuah proses closing dianggap mati (Addendum 44). */
    private const MENIT_DIAM = 15;

    protected $signature = 'periode:pulihkan-closing {--menit= : batas menit tanpa progres}';

    protected $description = 'Kembalikan periode yang tertinggal di status CLOSING ke status sebelumnya';

    public function handle(): int
    {
        $batas = now()->subMinutes((int) ($this->option('menit') ?: self::MENIT_DIAM));
        $tertinggal = FiscalPeriod::where('status', FiscalPeriod::STATUS_CLOSING)->get();
        $dipulihkan = 0;

        foreach ($tertinggal as $period) {
            $langkahTerakhir = ClosingAttemptLog::where('fiscal_period_id', $period->fiscal_period_id)
                ->orderByDesc('attempt_log_id')
                ->value('started_at');

            // Masih ada progres belakangan ini: biarkan berjalan.
            if ($langkahTerakhir !== null && $langkahTerakhir->greaterThan($batas)) {
                continue;
            }

            $this->pulihkan($period);
            $dipulihkan++;

            $this->warn("{$period->period_code} dikembalikan ke {$period->status}.");
        }

        $this->info($dipulihkan === 0
            ? 'Tidak ada periode yang tertinggal di status CLOSING.'
            : "{$dipulihkan} periode dipulihkan.");

        return self::SUCCESS;
    }

    private function pulihkan(FiscalPeriod $period): void
    {
        // Status sebelum penutupan dibaca dari jejaknya sendiri; OPEN dipakai
        // sebagai cadangan kalau jejak itu hilang.
        $asal = FiscalPeriodLog::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('action', FiscalPeriodLog::ACTION_CLOSING)
            ->orderByDesc('log_id')
            ->value('old_status') ?? FiscalPeriod::STATUS_OPEN;

        DB::transaction(function () use ($period, $asal) {
            $period->update(['status' => $asal]);

            FiscalPeriodLog::create([
                'fiscal_period_id' => $period->fiscal_period_id,
                'action' => FiscalPeriodLog::ACTION_RECOVERED,
                'old_status' => FiscalPeriod::STATUS_CLOSING,
                'new_status' => $asal,
                'action_at' => now(),
                'reason' => 'Proses penutupan tidak menunjukkan progres lebih dari '.self::MENIT_DIAM.' menit; status dikembalikan otomatis.',
            ]);
        });

        $period->setAttribute('status', $asal);
    }
}
