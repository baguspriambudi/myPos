import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Package, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Produk', href: '/master/produk' },
];

interface Satuan {
    id?: number;
    name: string;
    type: string;
    factor: number;
    price: number;
    active: boolean;
}

interface Produk {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    categoryId: number | null;
    categoryName: string | null;
    isWeighted: boolean;
    active: boolean;
    baseUomId: number | null;
    uoms: Satuan[];
}

/**
 * Index signature-nya wajib: useForm Inertia hanya menerima nilai bersarang
 * yang cocok dengan FormDataConvertible, dan interface biasa tidak memenuhinya.
 */
interface BarisSatuan {
    id: number | null;
    uom_name: string;
    uom_type: string;
    conversion_factor: number;
    selling_price: number;
    active: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

const SATUAN_BARU: BarisSatuan = {
    id: null,
    uom_name: '',
    uom_type: 'PACK',
    conversion_factor: 1,
    selling_price: 0,
    active: true,
};

export default function ProdukMasterIndex() {
    const { products, filters, categories, auth } = usePage().props as unknown as {
        products: Paginated<Produk>;
        filters: { q: string; category: number | null };
        categories: { id: number; name: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Produk | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        product_name: '',
        sku: '',
        barcode: '',
        category_id: '' as string | number,
        is_weighted: false as boolean,
        active: true as boolean,
        uoms: [
            { id: null, uom_name: 'PCS', uom_type: 'BASE', conversion_factor: 1, selling_price: 0, active: true },
        ] as BarisSatuan[],
    });

    const bukaTambah = () => {
        setSedangDiubah(null);
        form.setData({
            product_name: '',
            sku: '',
            barcode: '',
            category_id: '',
            is_weighted: false,
            active: true,
            uoms: [{ id: null, uom_name: 'PCS', uom_type: 'BASE', conversion_factor: 1, selling_price: 0, active: true }],
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (p: Produk) => {
        setSedangDiubah(p);
        form.setData({
            product_name: p.name,
            sku: p.sku,
            barcode: p.barcode ?? '',
            category_id: p.categoryId ?? '',
            is_weighted: p.isWeighted,
            active: p.active,
            uoms: p.uoms.map((u) => ({
                id: u.id ?? null,
                uom_name: u.name,
                uom_type: u.type,
                conversion_factor: u.factor,
                selling_price: u.price,
                active: u.active,
            })),
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const ubahSatuan = (index: number, patch: Partial<BarisSatuan>) => {
        form.setData(
            'uoms',
            form.data.uoms.map((baris, i) => (i === index ? { ...baris, ...patch } : baris)),
        );
    };

    const tambahSatuan = () => form.setData('uoms', [...form.data.uoms, { ...SATUAN_BARU }]);

    const hapusSatuan = (index: number) =>
        form.setData(
            'uoms',
            form.data.uoms.filter((_, i) => i !== index),
        );

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/produk/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/produk', opsi);
        }
    };

    const filterKategori = (v: string) => {
        router.get('/master/produk', v === '' ? {} : { category: v }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Produk" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Produk"
                    subtitle="Stok selalu disimpan dalam satuan dasar. Satuan lain adalah kelipatannya."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={bukaTambah}>
                                <Plus className="h-3.5 w-3.5" /> Tambah produk
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar produk"
                    description={`${products.total} produk terdaftar`}
                    bodyClassName="p-0"
                    action={
                        <div className="flex items-center gap-2">
                            <NativeSelect
                                value={filters.category ?? ''}
                                onChange={filterKategori}
                                placeholder="Semua kategori"
                                options={categories.map((c) => ({ value: c.id, label: c.name }))}
                                className="w-40"
                            />
                            <ServerSearch routeUrl="/master/produk" initial={filters.q} placeholder="Cari nama, SKU, barcode..." className="w-56" />
                        </div>
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="w-36">Kategori</TableHead>
                                <TableHead>Satuan & harga standar</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 5 : 4} icon={<Package />} message="Belum ada produk." />
                            )}

                            {products.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell>
                                        <p className="font-medium">{p.name}</p>
                                        <p className="font-mono text-2xs text-muted-foreground">
                                            {p.sku}
                                            {p.barcode && ` · ${p.barcode}`}
                                            {p.isWeighted && ' · ditimbang'}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">{p.categoryName ?? '—'}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1.5">
                                            {p.uoms
                                                .filter((u) => u.active)
                                                .map((u) => (
                                                    <span
                                                        key={u.id}
                                                        className="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-2xs"
                                                    >
                                                        <span className="font-semibold">{u.name}</span>
                                                        {u.factor !== 1 && (
                                                            <span className="font-mono text-muted-foreground tabular">
                                                                ×{formatQty(u.factor)}
                                                            </span>
                                                        )}
                                                        <span className="font-mono tabular">{formatRupiah(u.price)}</span>
                                                    </span>
                                                ))}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={p.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => bukaUbah(p)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/produk/${p.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={products} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah produk' : 'Tambah produk'}</DialogTitle>
                        <DialogDescription>
                            Tepat satu satuan harus bertipe BASE — itulah satuan penyimpanan stok.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama produk" required error={form.errors.product_name}>
                            <Input
                                value={form.data.product_name}
                                onChange={(e) => form.setData('product_name', e.target.value)}
                                className="h-8"
                                placeholder="Rinso Cair Sachet 45g"
                            />
                        </Field>

                        <div className="grid grid-cols-3 gap-3">
                            <Field label="SKU" required error={form.errors.sku}>
                                <Input
                                    value={form.data.sku}
                                    onChange={(e) => form.setData('sku', e.target.value.toUpperCase())}
                                    className="h-8 font-mono uppercase"
                                />
                            </Field>
                            <Field label="Barcode" error={form.errors.barcode}>
                                <Input
                                    value={form.data.barcode}
                                    onChange={(e) => form.setData('barcode', e.target.value)}
                                    className="h-8 font-mono"
                                />
                            </Field>
                            <Field label="Kategori" error={form.errors.category_id}>
                                <NativeSelect
                                    value={form.data.category_id}
                                    onChange={(v) => form.setData('category_id', v)}
                                    placeholder="Tanpa kategori"
                                    options={categories.map((c) => ({ value: c.id, label: c.name }))}
                                />
                            </Field>
                        </div>

                        {/* ------------------------------------------------ satuan */}
                        <div className="space-y-2 rounded-md border p-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-semibold">Satuan</p>
                                    <p className="text-2xs text-muted-foreground">
                                        Faktor = berapa satuan dasar dalam satu satuan ini. DUS ×12 berarti 1 dus = 12 pcs.
                                    </p>
                                </div>
                                <Button variant="outline" size="sm" className="h-7 text-xs" onClick={tambahSatuan}>
                                    <Plus className="h-3 w-3" /> Satuan
                                </Button>
                            </div>

                            {form.errors.uoms && <p className="text-2xs font-medium text-destructive">{form.errors.uoms}</p>}

                            <div className="space-y-1.5">
                                <div className="grid grid-cols-[1fr_5.5rem_5.5rem_7rem_2rem] gap-2 px-1">
                                    <span className="label-eyebrow">Nama</span>
                                    <span className="label-eyebrow">Tipe</span>
                                    <span className="label-eyebrow">Faktor</span>
                                    <span className="label-eyebrow">Harga standar</span>
                                    <span />
                                </div>

                                {form.data.uoms.map((baris, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_5.5rem_5.5rem_7rem_2rem] items-center gap-2">
                                        <Input
                                            value={baris.uom_name}
                                            onChange={(e) => ubahSatuan(i, { uom_name: e.target.value.toUpperCase() })}
                                            className="h-7 font-mono uppercase"
                                            placeholder="DUS"
                                        />
                                        <NativeSelect
                                            value={baris.uom_type}
                                            onChange={(v) =>
                                                ubahSatuan(i, { uom_type: v, conversion_factor: v === 'BASE' ? 1 : baris.conversion_factor })
                                            }
                                            options={[
                                                { value: 'BASE', label: 'BASE' },
                                                { value: 'PACK', label: 'PACK' },
                                            ]}
                                            className="h-7"
                                        />
                                        <Input
                                            type="number"
                                            step="0.000001"
                                            value={baris.conversion_factor}
                                            onChange={(e) => ubahSatuan(i, { conversion_factor: Number(e.target.value) })}
                                            className="h-7 font-mono tabular"
                                            disabled={baris.uom_type === 'BASE'}
                                        />
                                        <Input
                                            type="number"
                                            step="1"
                                            value={baris.selling_price}
                                            onChange={(e) => ubahSatuan(i, { selling_price: Number(e.target.value) })}
                                            className="h-7 font-mono tabular"
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7 text-muted-foreground hover:text-destructive"
                                            onClick={() => hapusSatuan(i)}
                                            disabled={form.data.uoms.length <= 1}
                                            aria-label="Hapus satuan"
                                        >
                                            <Trash2 className="h-3 w-3" />
                                        </Button>
                                    </div>
                                ))}
                            </div>

                            <p className="text-2xs text-muted-foreground">
                                Satuan yang dibuang dari daftar hanya dinonaktifkan, tidak dihapus, agar transaksi lama tetap utuh.
                            </p>
                        </div>

                        <div className="flex items-center gap-4">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.is_weighted}
                                    onCheckedChange={(v) => form.setData('is_weighted', v === true)}
                                />
                                <span className="text-xs font-medium">Ditimbang (qty desimal)</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} />
                                <span className="text-xs font-medium">Aktif</span>
                            </label>
                        </div>
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
