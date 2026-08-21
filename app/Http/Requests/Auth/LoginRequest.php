<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login memakai username, bukan email — POS tidak menyimpan email pengguna.
 *
 * Username hanya unik PER OWNER (uk_users_username), jadi satu username bisa
 * ada di dua usaha yang berbeda. Alih-alih memaksa kasir selalu mengetik kode
 * usaha, owner dicari otomatis; kode usaha baru diminta bila ternyata ambigu.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'owner_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => 'nama pengguna',
            'password' => 'kata sandi',
            'owner_code' => 'kode usaha',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $username = (string) $this->string('username');
        $ownerCode = trim((string) $this->string('owner_code'));

        $candidates = User::query()
            ->where('username', $username)
            ->where('active', true)
            ->when($ownerCode !== '', fn ($q) => $q->whereHas(
                'owner',
                fn ($o) => $o->where('owner_code', $ownerCode)
            ))
            ->get();

        if ($candidates->count() > 1) {
            // Ambigu: username yang sama ada di beberapa usaha.
            throw ValidationException::withMessages([
                'owner_code' => 'Nama pengguna ini terdaftar di beberapa usaha. Isi kode usaha untuk melanjutkan.',
            ]);
        }

        $user = $candidates->first();

        if ($user === null || ! Auth::attempt(
            ['user_id' => $user->user_id, 'password' => $this->string('password')],
            $this->boolean('remember')
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => 'Nama pengguna atau kata sandi salah.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}
