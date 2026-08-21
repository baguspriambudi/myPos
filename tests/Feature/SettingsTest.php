<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pengaturan akun sendiri.
 *
 * Menggantikan test bawaan starter kit yang menguji registrasi mandiri,
 * verifikasi email, reset kata sandi lewat email, dan penghapusan akun sendiri
 * — empat alur yang sengaja ditiadakan pada POS ini.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OwnerSeeder::class);
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    public function test_halaman_profil_tampil(): void
    {
        $this->actingAs($this->kasir())->get('/settings/profile')->assertOk();
    }

    public function test_nama_lengkap_dapat_diubah(): void
    {
        $this->actingAs($this->kasir())
            ->patch('/settings/profile', ['full_name' => 'Dwi Lestari Wijaya'])
            ->assertRedirect('/settings/profile');

        $this->assertSame('Dwi Lestari Wijaya', $this->kasir()->full_name);
    }

    public function test_username_tidak_ikut_berubah_dari_halaman_profil(): void
    {
        // Username dipakai untuk login; penggantiannya hanya lewat master Pengguna.
        $this->actingAs($this->kasir())
            ->patch('/settings/profile', ['full_name' => 'Dwi Lestari', 'username' => 'bajakan'])
            ->assertRedirect('/settings/profile');

        $this->assertSame('kasir', $this->kasir()->username);
    }

    public function test_kata_sandi_dapat_diganti_sendiri(): void
    {
        $this->actingAs($this->kasir())
            ->put('/settings/password', [
                'current_password' => OwnerSeeder::PASSWORD,
                'password' => 'sandibaru123',
                'password_confirmation' => 'sandibaru123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('sandibaru123', $this->kasir()->password_hash));
    }

    public function test_kata_sandi_lama_yang_salah_ditolak(): void
    {
        $this->actingAs($this->kasir())
            ->put('/settings/password', [
                'current_password' => 'salah',
                'password' => 'sandibaru123',
                'password_confirmation' => 'sandibaru123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(OwnerSeeder::PASSWORD, $this->kasir()->password_hash));
    }

    public function test_alur_yang_ditiadakan_tidak_dapat_diakses(): void
    {
        // Pengguna POS tidak mendaftar sendiri dan tidak punya email.
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->actingAs($this->kasir())->delete('/settings/profile')->assertMethodNotAllowed();
    }

    public function test_logout_mengakhiri_sesi(): void
    {
        $this->actingAs($this->kasir())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
