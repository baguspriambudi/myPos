import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface LoginForm {
    username: string;
    password: string;
    owner_code: string;
    remember: boolean;
    [key: string]: string | boolean;
}

export default function Login({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        username: '',
        password: '',
        owner_code: '',
        remember: false,
    });

    // Kode usaha hanya diminta bila server bilang username-nya ambigu, supaya
    // kasir tidak perlu mengetiknya setiap ganti shift.
    const [tampilkanKodeUsaha, setTampilkanKodeUsaha] = useState(false);

    // Kata sandi kasir sering diketik di layar sentuh yang kecil; tanpa cara
    // mengintipnya, salah ketik satu huruf hanya terlihat sebagai "gagal masuk".
    const [tampilkanSandi, setTampilkanSandi] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onError: (err) => {
                if (err.owner_code) setTampilkanKodeUsaha(true);
            },
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Masuk ke myPOS" description="Gunakan nama pengguna dan kata sandi dari admin toko">
            <Head title="Masuk" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="username">Nama pengguna</Label>
                        <Input
                            id="username"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="username"
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value.toLowerCase())}
                            placeholder="kasir"
                            className="font-mono lowercase"
                        />
                        <InputError message={errors.username} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Kata sandi</Label>
                        <div className="relative">
                            <Input
                                id="password"
                                type={tampilkanSandi ? 'text' : 'password'}
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                                className="pr-9"
                            />
                            <button
                                type="button"
                                // Sengaja di luar urutan tab: menekan Tab dari kolom
                                // sandi harus langsung ke tombol Masuk, bukan mampir
                                // ke tombol intip.
                                tabIndex={-1}
                                onClick={() => setTampilkanSandi((v) => !v)}
                                className="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2"
                                aria-label={tampilkanSandi ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                                aria-pressed={tampilkanSandi}
                                title={tampilkanSandi ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                            >
                                {tampilkanSandi ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </button>
                        </div>
                        <InputError message={errors.password} />
                    </div>

                    {(tampilkanKodeUsaha || errors.owner_code) && (
                        <div className="grid gap-2">
                            <Label htmlFor="owner_code">Kode usaha</Label>
                            <Input
                                id="owner_code"
                                type="text"
                                tabIndex={3}
                                value={data.owner_code}
                                onChange={(e) => setData('owner_code', e.target.value.toUpperCase())}
                                placeholder="SM"
                                className="font-mono uppercase"
                            />
                            <InputError message={errors.owner_code} />
                        </div>
                    )}

                    <div className="flex items-center space-x-3">
                        <Checkbox
                            id="remember"
                            name="remember"
                            tabIndex={4}
                            checked={data.remember}
                            onCheckedChange={(v) => setData('remember', v === true)}
                        />
                        <Label htmlFor="remember">Ingat saya</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={5} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Masuk
                    </Button>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    Lupa kata sandi? Hubungi pemilik atau admin toko untuk mengaturnya ulang.
                </p>
            </form>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}
