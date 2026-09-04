<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master satuan milik satu usaha: PCS, DUS, KG, dan seterusnya.
 *
 * Di sini hanya ada NAMANYA. Tipe (BASE/PACK) dan conversion_factor tidak
 * disimpan di master karena keduanya melekat pada produk — satu DUS Indomie
 * berisi 40 pcs sedangkan satu DUS Aqua berisi 24 pcs.
 */
class Unit extends Model
{
    protected $table = 'units';

    protected $primaryKey = 'unit_id';

    protected $fillable = ['owner_id', 'unit_name', 'description', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    /** Pemakaian satuan ini pada produk. */
    public function productUoms(): HasMany
    {
        return $this->hasMany(ProductUom::class, 'unit_id');
    }

    /** Sudah menempel pada produk — nama tidak boleh diubah lagi. */
    public function isAssigned(): bool
    {
        return $this->productUoms()->exists();
    }
}
