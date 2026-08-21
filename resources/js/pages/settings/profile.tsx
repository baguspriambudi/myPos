import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profil',
        href: '/settings/profile',
    },
];

interface Profil {
    fullName: string;
    username: string;
    roleLabel: string;
    ownerName: string | null;
}

export default function Profile() {
    const { profile } = usePage().props as unknown as { profile: Profil };

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        full_name: profile.fullName,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profil" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Profil" description="Ubah nama tampilan Anda" />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="full_name">Nama lengkap</Label>

                            <Input
                                id="full_name"
                                className="mt-1 block w-full"
                                value={data.full_name}
                                onChange={(e) => setData('full_name', e.target.value)}
                                required
                                autoComplete="name"
                            />

                            <InputError className="mt-2" message={errors.full_name} />
                        </div>

                        {/* Username, peran, dan usaha hanya bisa diubah dari master Pengguna
                            oleh Pemilik/Super Admin — username dipakai untuk login. */}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label>Nama pengguna</Label>
                                <Input value={profile.username} className="font-mono" disabled />
                            </div>
                            <div className="grid gap-2">
                                <Label>Peran</Label>
                                <Input value={profile.roleLabel} disabled />
                            </div>
                            <div className="grid gap-2">
                                <Label>Usaha</Label>
                                <Input value={profile.ownerName ?? '—'} disabled />
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Nama pengguna dan peran hanya dapat diubah dari Master → Pengguna oleh Pemilik atau Super Admin.
                        </p>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Simpan</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Tersimpan</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
