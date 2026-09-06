<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Fiscal period. branch_id NULL = berlaku seluruh lokasi milik owner.
 *
 * Gudang juga wajib punya period: transfer ditolak bila salah satu sisi tidak
 * punya period OPEN (Addendum 49.1 & 59.2).
 *
 * Daur hidupnya searah kecuali satu jalan memutar (Addendum 43.1):
 *
 *   DRAFT -> OPEN -> CLOSING -> CLOSED
 *                       ^          |
 *                       |          v
 *                       +------ REOPENED   (lewat permintaan + persetujuan)
 */
class FiscalPeriod extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_OPEN = 'OPEN';

    /** Penutupan sedang berjalan; seluruh transaksi period ini ditolak (Addendum 43.2). */
    public const STATUS_CLOSING = 'CLOSING';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_REOPENED = 'REOPENED';

    protected $table = 'fiscal_periods';

    protected $primaryKey = 'fiscal_period_id';

    protected $fillable = [
        'owner_id',
        'branch_id',
        'period_code',
        'period_name',
        'start_date',
        'end_date',
        'status',
        'costing_method',
        'currency_code',
        'opened_at',
        'opened_by',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function closings(): HasMany
    {
        return $this->hasMany(FiscalClosing::class, 'fiscal_period_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FiscalPeriodLog::class, 'fiscal_period_id');
    }

    public function reopenRequests(): HasMany
    {
        return $this->hasMany(FiscalPeriodReopenRequest::class, 'fiscal_period_id');
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(OpeningBalance::class, 'fiscal_period_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_REOPENED]);
    }

    /**
     * Period lain pada scope yang sama: owner sama, branch sama persis.
     *
     * Sengaja BUKAN query scope: scope dijalankan atas model kosong milik
     * builder, sehingga owner_id dan branch_id yang dibaca akan kosong pula.
     */
    public function lingkupSama(): Builder
    {
        return self::query()
            ->where('owner_id', $this->owner_id)
            ->when(
                $this->branch_id === null,
                fn (Builder $q) => $q->whereNull('branch_id'),
                fn (Builder $q) => $q->where('branch_id', $this->branch_id),
            )
            ->whereKeyNot($this->fiscal_period_id);
    }

    public function menerimaTransaksi(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_REOPENED], true);
    }

    /** Snapshot resmi terakhir; yang REPLACED sengaja tidak terbaca (Addendum 25). */
    public function snapshot(): ?FiscalClosing
    {
        return $this->closings()->valid()->orderByDesc('version')->first();
    }

    /**
     * Period tepat sebelum period ini pada scope yang sama.
     *
     * Dipakai closing untuk mengambil saldo awal: period kedua dan seterusnya
     * TIDAK mengisi opening lewat Initial Opening melainkan mewarisi ending
     * period sebelumnya (Addendum 40.4).
     */
    public function sebelumnya(): ?self
    {
        return $this->lingkupSama()
            ->whereDate('end_date', '<', $this->start_date)
            ->orderByDesc('end_date')
            ->first();
    }

    /** Period-period sesudah ini pada scope yang sama, terurut maju. */
    public function sesudahnya(): Collection
    {
        return $this->lingkupSama()
            ->whereDate('start_date', '>', $this->end_date)
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Lokasi yang tercakup period ini.
     *
     * Period tingkat owner (branch_id NULL) mencakup SELURUH lokasi milik
     * owner — pemeriksaan pra-tutup yang bertumpu pada saldo per lokasi
     * (stok negatif, misalnya) harus menyapu semuanya, bukan satu pun terlewat.
     *
     * @return array<int, int>
     */
    public function branchIds(): array
    {
        if ($this->branch_id !== null) {
            return [$this->branch_id];
        }

        return Branch::where('owner_id', $this->owner_id)->pluck('branch_id')->all();
    }
}
