<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Pengguna POS. Berbeda dari starter kit Laravel: tidak ada email, login
 * memakai username yang unik PER OWNER (multi-tenant), dan kolom passwordnya
 * bernama password_hash mengikuti database-schema.sql.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_OWNER = 'OWNER';

    public const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';

    public const ROLE_MANAGER = 'MANAGER';

    public const ROLE_KASIR = 'KASIR';

    public const ROLE_GUDANG = 'GUDANG';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_SUPER_ADMIN,
        self::ROLE_MANAGER,
        self::ROLE_KASIR,
        self::ROLE_GUDANG,
    ];

    /** Role yang boleh mengelola master data (Addendum 50). */
    public const ROLES_MASTER_ADMIN = [self::ROLE_OWNER, self::ROLE_SUPER_ADMIN];

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'owner_id',
        'branch_id',
        'username',
        'password_hash',
        'full_name',
        'role',
        'active',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'password_hash' => 'hashed',
        ];
    }

    /**
     * Laravel mencari kolom "password" secara bawaan. Schema memakai
     * password_hash, jadi arahkan ulang di sini daripada mengubah schema.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** Lokasi yang boleh diakses MANAGER / KASIR / GUDANG (Addendum 50). */
    public function branchAccess(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_access', 'user_id', 'branch_id');
    }

    public function isMasterAdmin(): bool
    {
        return in_array($this->role, self::ROLES_MASTER_ADMIN, true);
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_OWNER => 'Pemilik',
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            self::ROLE_MANAGER => 'Manajer',
            self::ROLE_KASIR => 'Kasir',
            self::ROLE_GUDANG => 'Gudang',
            default => $this->role,
        };
    }

    /**
     * Lokasi yang benar-benar boleh dilihat user ini.
     * branch_id NULL pada OWNER/SUPER_ADMIN berarti seluruh lokasi milik owner.
     */
    public function accessibleBranches()
    {
        if ($this->isMasterAdmin() && $this->branch_id === null) {
            return Branch::where('owner_id', $this->owner_id)->active()->orderBy('branch_code')->get();
        }

        $ids = $this->branchAccess()->pluck('branches.branch_id')->all();

        if ($this->branch_id !== null) {
            $ids[] = $this->branch_id;
        }

        return Branch::where('owner_id', $this->owner_id)
            ->whereIn('branch_id', array_unique($ids))
            ->active()
            ->orderBy('branch_code')
            ->get();
    }
}
