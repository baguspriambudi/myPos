<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartOfAccount extends Model
{
    public $timestamps = false;

    /** Akun penampung barang diterima yang fakturnya belum masuk (Addendum 58.5). */
    public const CODE_GRNI = '2-1200';

    protected $table = 'chart_of_accounts';

    protected $primaryKey = 'account_id';

    protected $fillable = ['owner_id', 'account_code', 'account_name', 'account_type'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function typeLabel(): string
    {
        return match ($this->account_type) {
            'ASSET' => 'Aset',
            'LIABILITY' => 'Kewajiban',
            'EQUITY' => 'Ekuitas',
            'REVENUE' => 'Pendapatan',
            'EXPENSE' => 'Beban',
            default => $this->account_type,
        };
    }
}
