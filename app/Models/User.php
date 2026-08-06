<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Pengguna dashboard: owner dan manajer kafe.
 *
 * Sejak modul self-service ada, karyawan JUGA punya akun di sini, terhubung
 * 1:1 ke barisnya di tabel employees lewat employee_id. Manager dan owner tidak
 * punya employee_id karena mereka bukan pegawai yang diabsen.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'employee_id', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function isManagement(): bool
    {
        return $this->role->isManagement();
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isEmployee(): bool
    {
        return $this->role->isEmployee();
    }

    public function isOwner(): bool
    {
        return $this->role->isOwner();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
