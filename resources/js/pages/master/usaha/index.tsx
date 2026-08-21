import { Head, useForm, usePage } from '@inertiajs/react';
import { Field, FlashMessage, NativeSelect } from '@/components/master-ui';
import { PageHeader, Panel } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Profil Usaha', href: '/master/usaha' },
];

export default function UsahaIndex() {
    const { owner, hasOpenPeriod, auth } = usePage().props as unknown as {
        owner: {
            code: string;
            name: string;
            defaultCurrency: string;
            costingMethod: string;
            allowNegativeStock: boolean;
            paymentRoundingUnit: number;
            paymentRoundingMode: string;
        };
        hasOpenPeriod: boolean;
        auth: { user: { isMasterAdmin: boolean } };
    };

    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        owner_name: owner.name,
        costing_method: owner.costingMethod,
        allow_negative_stock: owner.allowNegativeStock,
        payment_rounding_unit: owner.paymentRoundingUnit,
        payment_rounding_mode: owner.paymentRoundingMode,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profil Usaha" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Profil Usaha"
                    subtitle={`Kode usaha ${owner.code} · mata uang ${owner.defaultCurrency}`}
                />

                <FlashMessage />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Identitas" description="Kode usaha dipakai saat login bila ada username kembar antar-usaha.">
                        <div className="grid gap-3">
                            <Field label="Nama usaha" required error={form.errors.owner_name}>
                                <Input
                                    value={form.data.owner_name}
                                    onChange={(e) => form.setData('owner_name', e.target.value)}
                                    className="h-8"
                                    disabled={!bolehUbah}
                                />
                            </Field>
                            <Field label="Kode usaha">
                                <Input value={owner.code} className="h-8 font-mono" disabled />
                            </Field>
                        </div>
                    </Panel>

                    <Panel title="Persediaan" description="Berlaku untuk seluruh lokasi milik usaha ini.">
                        <div className="grid gap-3">
                            <Field
                                label="Metode HPP"
                                required
                                error={form.errors.costing_method}
                                hint={
                                    hasOpenPeriod
                                        ? 'Tidak dapat diubah selama masih ada periode terbuka.'
                                        : 'Di-snapshot ke periode saat periode dibuka.'
                                }
                            >
                                <NativeSelect
                                    value={form.data.costing_method}
                                    onChange={(v) => form.setData('costing_method', v)}
                                    options={[
                                        { value: 'AVERAGE', label: 'Rata-rata bergerak' },
                                        { value: 'FIFO', label: 'FIFO' },
                                        { value: 'SPECIFIC', label: 'Identifikasi khusus (tahap lanjut)' },
                                    ]}
                                />
                            </Field>

                            <label className="flex items-start gap-2">
                                <Checkbox
                                    checked={form.data.allow_negative_stock}
                                    onCheckedChange={(v) => form.setData('allow_negative_stock', v === true)}
                                    className="mt-0.5"
                                    disabled={!bolehUbah}
                                />
                                <span className="text-xs">
                                    <span className="font-medium">Izinkan stok negatif</span>
                                    <span className="block text-2xs text-muted-foreground">
                                        Penjualan tetap jalan meski stok sistem habis. Hanya nyalakan bila pencatatan
                                        penerimaan sering terlambat.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </Panel>

                    <Panel
                        title="Pembulatan pembayaran"
                        description="Menyesuaikan uang tunai yang diterima ke pecahan terdekat. Nilai penjualan dan pajak tidak ikut berubah."
                        className="lg:col-span-2"
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Pecahan" required error={form.errors.payment_rounding_unit}>
                                <NativeSelect
                                    value={form.data.payment_rounding_unit}
                                    onChange={(v) => form.setData('payment_rounding_unit', Number(v))}
                                    options={[
                                        { value: 0, label: 'Nonaktif' },
                                        { value: 100, label: 'Rp100' },
                                        { value: 500, label: 'Rp500' },
                                        { value: 1000, label: 'Rp1.000' },
                                    ]}
                                />
                            </Field>
                            <Field label="Arah pembulatan" required error={form.errors.payment_rounding_mode}>
                                <NativeSelect
                                    value={form.data.payment_rounding_mode}
                                    onChange={(v) => form.setData('payment_rounding_mode', v)}
                                    options={[
                                        { value: 'NEAREST', label: 'Terdekat' },
                                        { value: 'DOWN', label: 'Ke bawah' },
                                        { value: 'UP', label: 'Ke atas' },
                                    ]}
                                />
                            </Field>
                        </div>
                    </Panel>
                </div>

                {bolehUbah && (
                    <div className="flex justify-end">
                        <Button size="sm" onClick={() => form.put('/master/usaha', { preserveScroll: true })} disabled={form.processing}>
                            Simpan pengaturan
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
