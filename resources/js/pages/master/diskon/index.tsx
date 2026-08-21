import { Head, useForm, usePage } from '@inertiajs/react';
import { BadgePercent, Plus } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Diskon', href: '/master/diskon' },
];

interface Diskon {
    id: number;
    name: string;
    scope: string;
    type: string;
    value: number;
    maxAmount: number | null;
    productId: number | null;
    productName: string | null;
    categoryId: number | null;
    categoryName: string | null;
    minQty: number;
    minAmount: number;
    startDate: string | null;
    endDate: string | null;
    active: boolean;
}

const hariIni = () => new Date().toISOString().slice(0, 10);

export default function DiskonIndex() {
    const { discounts, filters, products, categories, auth } = usePage().props as unknown as {
        discounts: Paginated<Diskon>;
        filters: { q: string };
        products: { id: number; name: string }[];
        categories: { id: number; name: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Diskon | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        discount_name: '',
        discount_scope: 'ITEM',
        discount_type: 'PERCENT',
        discount_value: 0,
        max_discount_amount: '' as string | number,
        product_id: '' as string | number,
        category_id: '' as string | number,
        min_qty: 0,
        min_amount: 0,
        start_date: hariIni(),
        end_date: hariIni(),
        active: true as boolean,
    });

    const buka = (d: Diskon | null) => {
        setSedangDiubah(d);
        form.setData({
            discount_name: d?.name ?? '',
            discount_scope: d?.scope ?? 'ITEM',
            discount_type: d?.type ?? 'PERCENT',
            discount_value: d?.value ?? 0,
            max_discount_amount: d?.maxAmount ?? '',
            product_id: d?.productId ?? '',
            category_id: d?.categoryId ?? '',
            min_qty: d?.minQty ?? 0,
            min_amount: d?.minAmount ?? 0,
            start_date: d?.startDate ?? hariIni(),
            end_date: d?.endDate ?? hariIni(),
            active: d?.active ?? true,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/diskon/${sedangDiubah.id}`, opsi) : form.post('/master/diskon', opsi);
    };

    const nilai = (d: Diskon) =>
        d.type === 'PERCENT' ? `${formatQty(d.value)}%` : formatRupiah(d.value);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Diskon" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Diskon"
                    subtitle="Diskon memotong harga setelah daftar harga menentukan harga dasarnya. Keduanya tidak saling menggantikan."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah diskon
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar diskon"
                    description={`${discounts.total} diskon terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/diskon" initial={filters.q} placeholder="Cari diskon..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-28">Cakupan</TableHead>
                                <TableHead className="w-28 text-right">Nilai</TableHead>
                                <TableHead>Sasaran & syarat</TableHead>
                                <TableHead className="w-40">Masa berlaku</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {discounts.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 7 : 6} icon={<BadgePercent />} message="Belum ada diskon." />
                            )}

                            {discounts.data.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell className="font-medium">{d.name}</TableCell>
                                    <TableCell>
                                        <Chip>{d.scope === 'ITEM' ? 'Per baris' : 'Per nota'}</Chip>
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-sm font-semibold tabular">{nilai(d)}</TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {d.productName ?? d.categoryName ?? 'Semua produk'}
                                        {d.minQty > 0 && ` · min ${formatQty(d.minQty)} qty`}
                                        {d.minAmount > 0 && ` · min ${formatRupiah(d.minAmount)}`}
                                        {d.maxAmount !== null && ` · maks ${formatRupiah(d.maxAmount)}`}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground tabular">
                                        {d.startDate && d.endDate ? `${formatDate(d.startDate)} – ${formatDate(d.endDate)}` : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={d.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(d)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/diskon/${d.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={discounts} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah diskon' : 'Tambah diskon'}</DialogTitle>
                        <DialogDescription>Kasir tidak boleh memberi diskon di luar master ini.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama diskon" required error={form.errors.discount_name}>
                            <Input value={form.data.discount_name} onChange={(e) => form.setData('discount_name', e.target.value)} className="h-8" />
                        </Field>

                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Cakupan" required error={form.errors.discount_scope}>
                                <NativeSelect
                                    value={form.data.discount_scope}
                                    onChange={(v) => form.setData('discount_scope', v)}
                                    options={[
                                        { value: 'ITEM', label: 'Per baris' },
                                        { value: 'TRANSACTION', label: 'Per nota' },
                                    ]}
                                />
                            </Field>
                            <Field label="Tipe" required error={form.errors.discount_type}>
                                <NativeSelect
                                    value={form.data.discount_type}
                                    onChange={(v) => form.setData('discount_type', v)}
                                    options={[
                                        { value: 'PERCENT', label: 'Persen' },
                                        { value: 'AMOUNT', label: 'Rupiah' },
                                    ]}
                                />
                            </Field>
                            <Field label="Nilai" required error={form.errors.discount_value}>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.data.discount_value}
                                    onChange={(e) => form.setData('discount_value', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                />
                            </Field>
                        </div>

                        {form.data.discount_type === 'PERCENT' && (
                            <Field label="Batas maksimum potongan" error={form.errors.max_discount_amount} hint="Kosongkan bila tanpa batas">
                                <Input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.max_discount_amount}
                                    onChange={(e) => form.setData('max_discount_amount', e.target.value === '' ? '' : Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                />
                            </Field>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Produk" error={form.errors.product_id} hint="Kosongkan untuk semua produk">
                                <NativeSelect
                                    value={form.data.product_id}
                                    onChange={(v) => form.setData('product_id', v)}
                                    placeholder="Semua produk"
                                    options={products.map((p) => ({ value: p.id, label: p.name }))}
                                />
                            </Field>
                            <Field label="Kategori" error={form.errors.category_id} hint="Kosongkan untuk semua kategori">
                                <NativeSelect
                                    value={form.data.category_id}
                                    onChange={(v) => form.setData('category_id', v)}
                                    placeholder="Semua kategori"
                                    options={categories.map((c) => ({ value: c.id, label: c.name }))}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Minimum qty" required error={form.errors.min_qty}>
                                <Input
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    value={form.data.min_qty}
                                    onChange={(e) => form.setData('min_qty', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                />
                            </Field>
                            <Field label="Minimum belanja" required error={form.errors.min_amount}>
                                <Input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.min_amount}
                                    onChange={(e) => form.setData('min_amount', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Mulai berlaku" required error={form.errors.start_date}>
                                <Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} className="h-8" />
                            </Field>
                            <Field label="Berlaku sampai" required error={form.errors.end_date}>
                                <Input type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} className="h-8" />
                            </Field>
                        </div>

                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} />
                            <span className="text-xs font-medium">Aktif</span>
                        </label>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
