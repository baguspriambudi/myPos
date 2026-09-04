import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerSearch } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, History, Layers, Lock, Plus, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Tingkat {
    id: number;
    productId: number;
    productName: string;
    sku: string;
    uomId: number;
    uomName: string;
    uomFactor: number;
    minQty: number;
    unitPrice: number;
    startDate: string;
    endDate: string;
    berlakuHariIni: boolean;
    /** Sudah mulai berlaku, jadi sudah jadi riwayat dan tidak boleh diubah. */
    terkunci: boolean;
}

interface ProdukPilihan {
    id: number;
    name: string;
    sku: string;
    uoms: { id: number; name: string; factor: number }[];
}

type Mode = 'tambah' | 'ubah' | 'ganti';

export default function HargaShow() {
    const { priceList, items, products, filters, today, tanpaAkhir, auth } = usePage().props as unknown as {
        priceList: {
            id: number;
            code: string;
            name: string;
            branchName: string | null;
            isDefault: boolean;
            memberOnly: boolean;
            active: boolean;
        };
        items: Tingkat[];
        products: ProdukPilihan[];
        filters: { q: string; riwayat: boolean };
        today: string;
        tanpaAkhir: string;
        auth: { user: { isMasterAdmin: boolean } };
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Master', href: '#' },
        { title: 'Daftar Harga', href: '/master/harga' },
        { title: priceList.name, href: `/master/harga/${priceList.id}` },
    ];

    const [sedangDiubah, setSedangDiubah] = useState<Tingkat | null>(null);
    const [mode, setMode] = useState<Mode>('tambah');
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        product_id: '' as string | number,
        uom_id: '' as string | number,
        min_qty: 1,
        unit_price: 0,
        start_date: today,
        end_date: tanpaAkhir,
    });

    const produkTerpilih = useMemo(
        () => products.find((p) => String(p.id) === String(form.data.product_id)) ?? null,
        [products, form.data.product_id],
    );

    const satuanTerpilih = useMemo(
        () => produkTerpilih?.uoms.find((u) => String(u.id) === String(form.data.uom_id)) ?? null,
        [produkTerpilih, form.data.uom_id],
    );

    /** Kelompokkan baris per produk supaya tangga harganya terbaca sebagai satu kesatuan. */
    const kelompok = useMemo(() => {
        const peta = new Map<number, { nama: string; sku: string; baris: Tingkat[] }>();

        for (const baris of items) {
            if (!peta.has(baris.productId)) {
                peta.set(baris.productId, { nama: baris.productName, sku: baris.sku, baris: [] });
            }
            peta.get(baris.productId)!.baris.push(baris);
        }

        return [...peta.entries()].map(([id, isi]) => ({ id, ...isi }));
    }, [items]);

    const bukaTambah = () => {
        setSedangDiubah(null);
        setMode('tambah');
        form.setData({ product_id: '', uom_id: '', min_qty: 1, unit_price: 0, start_date: today, end_date: tanpaAkhir });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (t: Tingkat) => {
        setSedangDiubah(t);
        setMode('ubah');
        form.setData({
            product_id: t.productId,
            uom_id: t.uomId,
            min_qty: t.minQty,
            unit_price: t.unitPrice,
            start_date: t.startDate,
            end_date: t.endDate,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    /*
     * Ganti harga = baris BARU dengan tanggal mulai lebih baru, bukan menimpa
     * yang lama. Baris lama sengaja dibiarkan utuh: dialah yang membuat
     * penjualan ber-backdate menemukan harga yang benar-benar berlaku saat itu.
     */
    const bukaGanti = (t: Tingkat) => {
        setSedangDiubah(null);
        setMode('ganti');
        form.setData({
            product_id: t.productId,
            uom_id: t.uomId,
            min_qty: t.minQty,
            unit_price: t.unitPrice,
            start_date: today,
            end_date: tanpaAkhir,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const gantiProduk = (v: string) => {
        // Satuan lama hampir pasti bukan milik produk baru, jadi dikosongkan.
        form.setData((data) => ({ ...data, product_id: v, uom_id: '' }));
    };

    const gantiSatuan = (v: string) => {
        form.setData((data) => ({ ...data, uom_id: v }));
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (mode === 'ubah' && sedangDiubah) {
            form.put(`/master/harga/${priceList.id}/tingkat/${sedangDiubah.id}`, opsi);
        } else {
            form.post(`/master/harga/${priceList.id}/tingkat`, opsi);
        }
    };

    const lihatRiwayat = (aktif: boolean) => {
        router.get(
            `/master/harga/${priceList.id}`,
            { ...(filters.q ? { q: filters.q } : {}), ...(aktif ? { riwayat: 1 } : {}) },
            { preserveState: true, preserveScroll: true },
        );
    };

    const tanpaBatas = form.data.end_date === tanpaAkhir;
    const judul = { tambah: 'Tambah tingkat harga', ubah: 'Ubah tingkat harga', ganti: 'Ganti harga' }[mode];

    /** Sampai kapan berlakunya; 9999-12-31 berarti tanpa batas akhir. */
    const sampai = (t: Tingkat) => (t.endDate === tanpaAkhir ? 'seterusnya' : formatDate(t.endDate));

    const status = (t: Tingkat) => {
        if (t.berlakuHariIni) return <Chip tone="accent">Berlaku</Chip>;
        if (t.startDate > today) return <Chip tone="warning">Terjadwal</Chip>;

        return <Chip>Berakhir</Chip>;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Harga — ${priceList.name}`} />

            <div className="space-y-4 p-4">
                <PageHeader
                    title={priceList.name}
                    subtitle={`${priceList.code} · ${priceList.branchName ?? 'Semua lokasi'} · ${items.length} baris harga`}
                    action={
                        <div className="flex items-center gap-2">
                            <Button asChild variant="outline" size="sm" className="h-8">
                                <Link href="/master/harga">
                                    <ArrowLeft className="h-3.5 w-3.5" /> Kembali
                                </Link>
                            </Button>
                            <Button
                                variant={filters.riwayat ? 'default' : 'outline'}
                                size="sm"
                                className="h-8"
                                onClick={() => lihatRiwayat(!filters.riwayat)}
                            >
                                <History className="h-3.5 w-3.5" /> {filters.riwayat ? 'Sembunyikan riwayat' : 'Lihat riwayat'}
                            </Button>
                            {bolehUbah && (
                                <Button size="sm" className="h-8" onClick={bukaTambah}>
                                    <Plus className="h-3.5 w-3.5" /> Tambah tingkat harga
                                </Button>
                            )}
                        </div>
                    }
                />

                <FlashMessage />

                <div className="flex flex-wrap items-center gap-1.5">
                    {priceList.isDefault && <Chip tone="accent">Harga normal</Chip>}
                    {priceList.memberOnly && <Chip tone="warning">Khusus member</Chip>}
                    <ActiveBadge active={priceList.active} />
                </div>

                <div className="bg-muted/30 text-muted-foreground rounded-md border border-dashed px-3 py-2.5 text-xs">
                    <span className="text-foreground font-medium">Harga tidak pernah ditimpa.</span> Mengganti harga membuat baris baru dengan tanggal
                    mulai lebih baru, dan yang paling baru mulai itulah yang dipakai. Baris lama dibiarkan supaya penjualan yang di-backdate menemukan
                    harga yang benar-benar berlaku saat itu — karena itu harga yang sudah mulai berlaku terkunci.
                </div>

                <Panel
                    title={filters.riwayat ? 'Tingkat harga & riwayatnya' : 'Tingkat harga yang berlaku hari ini'}
                    description="Baris dengan kuantitas minimum terbesar yang masih terpenuhi yang dipakai."
                    bodyClassName="p-0"
                    action={
                        <ServerSearch routeUrl={`/master/harga/${priceList.id}`} initial={filters.q} placeholder="Cari produk..." className="w-56" />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="w-24">Satuan</TableHead>
                                <TableHead className="w-28 text-right">Mulai qty</TableHead>
                                <TableHead className="w-32 text-right">Harga</TableHead>
                                <TableHead className="w-44">Masa berlaku</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-36 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.length === 0 && (
                                <EmptyRow
                                    colSpan={bolehUbah ? 7 : 6}
                                    icon={<Layers />}
                                    message="Belum ada tingkat harga. Produk yang tidak punya harga di daftar mana pun belum boleh dijual."
                                />
                            )}

                            {kelompok.map((grup) =>
                                grup.baris.map((baris, i) => (
                                    <TableRow key={baris.id} className={baris.berlakuHariIni ? undefined : 'opacity-60'}>
                                        <TableCell>
                                            {i === 0 ? (
                                                <div>
                                                    <p className="font-medium">{grup.nama}</p>
                                                    <p className="text-2xs text-muted-foreground font-mono">{grup.sku}</p>
                                                </div>
                                            ) : (
                                                <span className="text-2xs text-muted-foreground pl-3">↳</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Chip>{baris.uomName}</Chip>
                                            {baris.uomFactor !== 1 && (
                                                <span className="text-2xs text-muted-foreground tabular ml-1.5 font-mono">
                                                    ×{formatQty(baris.uomFactor)}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">
                                            {formatQty(baris.minQty)} {baris.uomName.toLowerCase()}
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-sm font-semibold">
                                            {formatRupiah(baris.unitPrice)}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">
                                            {formatDate(baris.startDate)} – {sampai(baris)}
                                        </TableCell>
                                        <TableCell>{status(baris)}</TableCell>
                                        {bolehUbah && (
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {baris.terkunci ? (
                                                        <>
                                                            <Lock className="text-muted-foreground h-3 w-3" aria-label="Sudah jadi riwayat" />
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 text-xs"
                                                                onClick={() => bukaGanti(baris)}
                                                            >
                                                                <RefreshCw className="h-3 w-3" /> Ganti harga
                                                            </Button>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <EditButton onClick={() => bukaUbah(baris)} />
                                                            <DeleteButton
                                                                onConfirm={() =>
                                                                    form.delete(`/master/harga/${priceList.id}/tingkat/${baris.id}`, {
                                                                        preserveScroll: true,
                                                                    })
                                                                }
                                                            />
                                                        </>
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                )),
                            )}
                        </TableBody>
                    </Table>
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{judul}</DialogTitle>
                        <DialogDescription>
                            {mode === 'ganti'
                                ? 'Harga lama dibiarkan utuh sebagai riwayat. Isi harga barunya dan sejak kapan berlaku.'
                                : 'Contoh: satu dus 20.000, satu pcs 2.500, dan mulai 6 pcs jadi 2.300 — tiga baris terpisah.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Produk" required error={form.errors.product_id}>
                            <NativeSelect
                                value={form.data.product_id}
                                onChange={gantiProduk}
                                placeholder="Pilih produk"
                                options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                                disabled={mode === 'ganti'}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="Satuan"
                                required
                                error={form.errors.uom_id}
                                hint={satuanTerpilih && satuanTerpilih.factor !== 1 ? `Isi ${satuanTerpilih.factor} satuan dasar` : undefined}
                            >
                                <NativeSelect
                                    value={form.data.uom_id}
                                    onChange={gantiSatuan}
                                    placeholder={produkTerpilih ? 'Pilih satuan' : 'Pilih produk dulu'}
                                    options={(produkTerpilih?.uoms ?? []).map((u) => ({
                                        value: u.id,
                                        label: u.factor === 1 ? u.name : `${u.name} (×${u.factor})`,
                                    }))}
                                    disabled={mode === 'ganti'}
                                />
                            </Field>

                            <Field label="Berlaku mulai qty" required error={form.errors.min_qty} hint="Dihitung dalam satuan di sebelah kiri">
                                <Input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    value={form.data.min_qty}
                                    onChange={(e) => form.setData('min_qty', Number(e.target.value))}
                                    className="tabular h-8 font-mono"
                                    disabled={mode === 'ganti'}
                                />
                            </Field>
                        </div>

                        <Field label="Harga per satuan" required error={form.errors.unit_price}>
                            <Input
                                type="number"
                                step="1"
                                min="0"
                                value={form.data.unit_price}
                                onChange={(e) => form.setData('unit_price', Number(e.target.value))}
                                className="tabular h-8 font-mono"
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Mulai berlaku" required error={form.errors.start_date} hint="Paling awal hari ini">
                                <Input
                                    type="date"
                                    min={today}
                                    value={form.data.start_date}
                                    onChange={(e) => form.setData('start_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                            <Field label="Berlaku sampai" required error={form.errors.end_date} hint="Isi hanya untuk harga promo">
                                <Input
                                    type="date"
                                    min={form.data.start_date}
                                    value={tanpaBatas ? '' : form.data.end_date}
                                    onChange={(e) => form.setData('end_date', e.target.value)}
                                    className="h-8"
                                    disabled={tanpaBatas}
                                />
                            </Field>
                        </div>

                        <label className="flex items-center gap-2">
                            <Checkbox checked={tanpaBatas} onCheckedChange={(v) => form.setData('end_date', v === true ? tanpaAkhir : '')} />
                            <span className="text-xs font-medium">Berlaku seterusnya</span>
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
