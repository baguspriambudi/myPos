import {
    ActiveBadge,
    Chip,
    DeleteButton,
    EditButton,
    Field,
    FlashMessage,
    NativeSelect,
    ServerPagination,
    ServerSearch,
    type Paginated,
} from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatQty } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Package, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Produk', href: '/master/produk' },
];

interface Satuan {
    id?: number;
    unitId: number;
    name: string;
    type: string;
    factor: number;
    active: boolean;
}

/** Master satuan; namanya tidak bisa diketik di layar ini. */
interface MasterSatuan {
    id: number;
    name: string;
    active: boolean;
}

/** Jenis produk beserta akibatnya; keduanya diturunkan dari jenis di server. */
interface JenisProduk {
    value: string;
    label: string;
    canSell: boolean;
    tracksStock: boolean;
}

interface Produk {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    categoryId: number | null;
    categoryName: string | null;
    productType: string;
    canSell: boolean;
    tracksStock: boolean;
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
    unit_id: number | null;
    uom_type: string;
    conversion_factor: number;
    active: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

export default function ProdukMasterIndex() {
    const { products, filters, categories, units, productTypes, auth } = usePage().props as unknown as {
        products: Paginated<Produk>;
        filters: { q: string; category: number | null };
        categories: { id: number; name: string }[];
        units: MasterSatuan[];
        productTypes: JenisProduk[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Produk | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    // PCS dipakai sebagai satuan dasar bawaan karena hampir selalu itu; tetap
    // bisa diganti dari dropdown.
    const satuanBawaan = units.find((u) => u.active && u.name === 'PCS')?.id ?? null;

    const barisBaru = (tipe: string): BarisSatuan => ({
        id: null,
        unit_id: tipe === 'BASE' ? satuanBawaan : null,
        uom_type: tipe,
        conversion_factor: 1,
        active: true,
    });

    const form = useForm({
        product_name: '',
        sku: '',
        barcode: '',
        category_id: '' as string | number,
        product_type: 'TRADE',
        is_weighted: false as boolean,
        active: true as boolean,
        uoms: [barisBaru('BASE')] as BarisSatuan[],
    });

    const bukaTambah = () => {
        setSedangDiubah(null);
        form.setData({
            product_name: '',
            sku: '',
            barcode: '',
            category_id: '',
            product_type: 'TRADE',
            is_weighted: false,
            active: true,
            uoms: [barisBaru('BASE')],
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
            product_type: p.productType,
            is_weighted: p.isWeighted,
            active: p.active,
            uoms: p.uoms.map((u) => ({
                id: u.id ?? null,
                unit_id: u.unitId,
                uom_type: u.type,
                conversion_factor: u.factor,
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

    const tambahSatuan = () => form.setData('uoms', [...form.data.uoms, barisBaru('PACK')]);

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

    /*
     * Layar ini tidak menampilkan BASE/PACK: pengguna hanya menunjuk satuan
     * terkecil lalu mendaftar isinya. Tipe tetap ikut dikirim karena itulah yang
     * dipahami database (Addendum 39.2).
     */
    const indexDasar = Math.max(
        0,
        form.data.uoms.findIndex((b) => b.uom_type === 'BASE'),
    );
    const barisDasar = form.data.uoms[indexDasar] ?? barisBaru('BASE');
    const barisKonversi = form.data.uoms.map((baris, i) => ({ baris, i })).filter(({ i }) => i !== indexDasar);
    const namaSatuanDasar = units.find((u) => u.id === barisDasar.unit_id)?.name ?? 'satuan dasar';

    /** Satu satuan tidak boleh dipakai dua baris; yang sudah terpakai disembunyikan dari pilihan. */
    const opsiSatuan = (dipilih: number | null) => {
        const dipakaiBarisLain = form.data.uoms.map((b) => b.unit_id).filter((id) => id !== null && id !== dipilih);

        return units
            .filter((u) => (u.active || u.id === dipilih) && !dipakaiBarisLain.includes(u.id))
            .map((u) => ({ value: u.id, label: u.active ? u.name : `${u.name} (nonaktif)` }));
    };

    const galatBaris = (i: number) => (form.errors as unknown as Record<string, string>)[`uoms.${i}.unit_id`];

    const jenisTerpilih = productTypes.find((t) => t.value === form.data.product_type);
    const jasa = jenisTerpilih?.tracksStock === false;

    /*
     * Jasa tidak punya kemasan. Barisnya dibuang saat jenisnya diganti, bukan
     * sekadar disembunyikan — kalau hanya disembunyikan, baris itu tetap ikut
     * terkirim dan ditolak server oleh galat yang tidak terlihat penyebabnya.
     */
    const gantiJenis = (v: string) => {
        const jadiJasa = productTypes.find((t) => t.value === v)?.tracksStock === false;

        form.setData(
            jadiJasa ? { ...form.data, product_type: v, uoms: [form.data.uoms[indexDasar] ?? barisBaru('BASE')] } : { ...form.data, product_type: v },
        );
    };

    /** Keterangan jenis selalu diturunkan dari server, tidak ditulis ulang di sini. */
    const keteranganJenis = jenisTerpilih
        ? [jenisTerpilih.canSell ? 'bisa dijual' : 'tidak dijual', jenisTerpilih.tracksStock ? 'punya stok' : 'tanpa stok'].join(' · ')
        : '';

    const filterKategori = (v: string) => {
        router.get('/master/produk', v === '' ? {} : { category: v }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Produk" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Produk"
                    subtitle="Stok selalu disimpan dalam satuan dasar; satuan lain adalah kelipatannya. Harga jual diatur di Daftar Harga."
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
                                <TableHead>Satuan & konversi</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && <EmptyRow colSpan={bolehUbah ? 5 : 4} icon={<Package />} message="Belum ada produk." />}

                            {products.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-medium">{p.name}</span>
                                            {/* Barang dagang adalah mayoritas; hanya yang menyimpang diberi tanda. */}
                                            {p.productType !== 'TRADE' && (
                                                <Chip tone={p.canSell ? 'accent' : 'warning'}>
                                                    {productTypes.find((t) => t.value === p.productType)?.label ?? p.productType}
                                                </Chip>
                                            )}
                                        </div>
                                        <p className="text-2xs text-muted-foreground font-mono">
                                            {p.sku}
                                            {p.barcode && ` · ${p.barcode}`}
                                            {p.isWeighted && ' · ditimbang'}
                                            {!p.tracksStock && ' · tanpa stok'}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-xs">{p.categoryName ?? '—'}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1.5">
                                            {p.uoms
                                                .filter((u) => u.active)
                                                .map((u) => (
                                                    <span key={u.id} className="text-2xs inline-flex items-center gap-1 rounded border px-1.5 py-0.5">
                                                        <span className="font-semibold">{u.name}</span>
                                                        {u.id === p.baseUomId ? (
                                                            <span className="text-muted-foreground">dasar</span>
                                                        ) : (
                                                            <span className="text-muted-foreground tabular font-mono">isi {formatQty(u.factor)}</span>
                                                        )}
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
                            Stok disimpan dalam satuan dasar. Kemasan besar cukup didaftarkan isinya — 1 DUS = 12 PCS. Harga jualnya diatur di Daftar
                            Harga.
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

                        <Field label="Jenis produk" required error={form.errors.product_type} hint={keteranganJenis}>
                            <NativeSelect
                                value={form.data.product_type}
                                onChange={gantiJenis}
                                options={productTypes.map((t) => ({ value: t.value, label: t.label }))}
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

                        {form.errors.uoms && <p className="text-2xs text-destructive font-medium">{form.errors.uoms}</p>}

                        {units.filter((u) => u.active).length === 0 && (
                            <p className="text-2xs text-destructive font-medium">
                                Belum ada satuan aktif. Tambahkan dulu lewat menu Master → Satuan.
                            </p>
                        )}

                        {/* ----------------------------------------- satuan dasar */}
                        <div className="space-y-2 rounded-md border p-3">
                            <div>
                                <p className="text-xs font-semibold">{jasa ? 'Satuan' : 'Satuan dasar'}</p>
                                <p className="text-2xs text-muted-foreground">
                                    {jasa
                                        ? 'Satuan penjualan jasa ini — misalnya KALI, JAM, atau RIT. Harganya tetap diatur di Daftar Harga.'
                                        : 'Satuan terkecil produk ini. Seluruh stok, HPP, dan laporan dicatat dalam satuan ini.'}
                                </p>
                            </div>

                            <div className="space-y-1.5">
                                <p className="label-eyebrow px-1">Satuan</p>
                                <NativeSelect
                                    value={barisDasar.unit_id}
                                    onChange={(v) => ubahSatuan(indexDasar, { unit_id: v === '' ? null : Number(v) })}
                                    placeholder="Pilih satuan"
                                    options={opsiSatuan(barisDasar.unit_id)}
                                    className="h-7 max-w-[12rem]"
                                />

                                {galatBaris(indexDasar) && <p className="text-2xs text-destructive px-1 font-medium">{galatBaris(indexDasar)}</p>}
                            </div>
                        </div>

                        {/* -------------------------------------- konversi satuan */}
                        {jasa ? (
                            <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-2.5">
                                Jasa tidak punya konversi satuan. Konversi menerjemahkan kemasan menjadi stok — 1 DUS menambah 12 PCS — dan jasa tidak
                                punya stok yang bisa bertambah atau berkurang.
                            </p>
                        ) : (
                            <div className="space-y-2 rounded-md border p-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-semibold">Konversi satuan</p>
                                        <p className="text-2xs text-muted-foreground">
                                            Kemasan yang dipakai saat beli/jual. 1 DUS berisi 12 {namaSatuanDasar} berarti membeli 1 DUS menambah stok
                                            12 {namaSatuanDasar}.
                                        </p>
                                    </div>
                                    <Button variant="outline" size="sm" className="h-7 text-xs" onClick={tambahSatuan}>
                                        <Plus className="h-3 w-3" /> Konversi
                                    </Button>
                                </div>

                                {barisKonversi.length === 0 ? (
                                    <p className="text-2xs text-muted-foreground">
                                        Belum ada. Produk yang hanya dibeli dan dijual eceran memang tidak perlu konversi.
                                    </p>
                                ) : (
                                    <div className="space-y-1.5">
                                        <div className="grid grid-cols-[1fr_7rem_2rem] gap-2 px-1">
                                            <span className="label-eyebrow">Satuan</span>
                                            <span className="label-eyebrow">Isi ({namaSatuanDasar})</span>
                                            <span />
                                        </div>

                                        {barisKonversi.map(({ baris, i }) => (
                                            <div key={i} className="space-y-1">
                                                <div className="grid grid-cols-[1fr_7rem_2rem] items-center gap-2">
                                                    <NativeSelect
                                                        value={baris.unit_id}
                                                        onChange={(v) => ubahSatuan(i, { unit_id: v === '' ? null : Number(v) })}
                                                        placeholder="Pilih satuan"
                                                        options={opsiSatuan(baris.unit_id)}
                                                        className="h-7"
                                                    />
                                                    <Input
                                                        type="number"
                                                        step="0.000001"
                                                        value={baris.conversion_factor}
                                                        onChange={(e) => ubahSatuan(i, { conversion_factor: Number(e.target.value) })}
                                                        className="tabular h-7 font-mono"
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground hover:text-destructive h-7 w-7"
                                                        onClick={() => hapusSatuan(i)}
                                                        aria-label="Hapus konversi"
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                                {galatBaris(i) && <p className="text-2xs text-destructive px-1 font-medium">{galatBaris(i)}</p>}
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <p className="text-2xs text-muted-foreground">
                                    Konversi yang dibuang dari daftar hanya dinonaktifkan, tidak dihapus, agar transaksi lama tetap utuh. Harga jual
                                    tiap satuan diatur di Master → Daftar Harga, bukan di sini.
                                </p>
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <label className="flex items-center gap-2">
                                <Checkbox checked={form.data.is_weighted} onCheckedChange={(v) => form.setData('is_weighted', v === true)} />
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
