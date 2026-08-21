import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Layers, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerSearch } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

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
    active: boolean;
}

interface ProdukPilihan {
    id: number;
    name: string;
    sku: string;
    uoms: { id: number; name: string; factor: number; standardPrice: number }[];
}

export default function HargaShow() {
    const { priceList, items, products, filters, auth } = usePage().props as unknown as {
        priceList: {
            id: number;
            code: string;
            name: string;
            branchName: string | null;
            isDefault: boolean;
            customerOnly: boolean;
            active: boolean;
        };
        items: Tingkat[];
        products: ProdukPilihan[];
        filters: { q: string };
        auth: { user: { isMasterAdmin: boolean } };
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Master', href: '#' },
        { title: 'Daftar Harga', href: '/master/harga' },
        { title: priceList.name, href: `/master/harga/${priceList.id}` },
    ];

    const [sedangDiubah, setSedangDiubah] = useState<Tingkat | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        product_id: '' as string | number,
        uom_id: '' as string | number,
        min_qty: 1,
        unit_price: 0,
        active: true as boolean,
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
        form.setData({ product_id: '', uom_id: '', min_qty: 1, unit_price: 0, active: true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (t: Tingkat) => {
        setSedangDiubah(t);
        form.setData({
            product_id: t.productId,
            uom_id: t.uomId,
            min_qty: t.minQty,
            unit_price: t.unitPrice,
            active: t.active,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const gantiProduk = (v: string) => {
        // Satuan lama hampir pasti bukan milik produk baru, jadi dikosongkan.
        form.setData((data) => ({ ...data, product_id: v, uom_id: '' }));
    };

    const gantiSatuan = (v: string) => {
        const satuan = produkTerpilih?.uoms.find((u) => String(u.id) === v);

        form.setData((data) => ({
            ...data,
            uom_id: v,
            // Harga standar dipakai sebagai titik awal, bukan nilai final.
            unit_price: data.unit_price > 0 ? data.unit_price : (satuan?.standardPrice ?? 0),
        }));
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/harga/${priceList.id}/tingkat/${sedangDiubah.id}`, opsi);
        } else {
            form.post(`/master/harga/${priceList.id}/tingkat`, opsi);
        }
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
                    {priceList.isDefault && <Chip tone="accent">Daftar bawaan</Chip>}
                    {priceList.customerOnly && <Chip tone="warning">Khusus pelanggan tertentu</Chip>}
                    <ActiveBadge active={priceList.active} />
                </div>

                <Panel
                    title="Tingkat harga"
                    description="Baris dengan kuantitas minimum terbesar yang masih terpenuhi yang dipakai."
                    bodyClassName="p-0"
                    action={
                        <ServerSearch
                            routeUrl={`/master/harga/${priceList.id}`}
                            initial={filters.q}
                            placeholder="Cari produk..."
                            className="w-56"
                        />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="w-28">Satuan</TableHead>
                                <TableHead className="w-32 text-right">Mulai qty</TableHead>
                                <TableHead className="w-36 text-right">Harga</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.length === 0 && (
                                <EmptyRow
                                    colSpan={bolehUbah ? 6 : 5}
                                    icon={<Layers />}
                                    message="Belum ada tingkat harga. Produk yang tidak diatur di sini memakai harga standar."
                                />
                            )}

                            {kelompok.map((grup) =>
                                grup.baris.map((baris, i) => (
                                    <TableRow key={baris.id}>
                                        <TableCell>
                                            {i === 0 ? (
                                                <div>
                                                    <p className="font-medium">{grup.nama}</p>
                                                    <p className="font-mono text-2xs text-muted-foreground">{grup.sku}</p>
                                                </div>
                                            ) : (
                                                <span className="pl-3 text-2xs text-muted-foreground">↳ tingkat lanjutan</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Chip>{baris.uomName}</Chip>
                                            {baris.uomFactor !== 1 && (
                                                <span className="ml-1.5 font-mono text-2xs text-muted-foreground tabular">
                                                    ×{formatQty(baris.uomFactor)}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-xs tabular">
                                            {formatQty(baris.minQty)} {baris.uomName.toLowerCase()}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-sm font-semibold tabular">
                                            {formatRupiah(baris.unitPrice)}
                                        </TableCell>
                                        <TableCell>
                                            <ActiveBadge active={baris.active} />
                                        </TableCell>
                                        {bolehUbah && (
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditButton onClick={() => bukaUbah(baris)} />
                                                    <DeleteButton
                                                        onConfirm={() =>
                                                            form.delete(`/master/harga/${priceList.id}/tingkat/${baris.id}`, {
                                                                preserveScroll: true,
                                                            })
                                                        }
                                                    />
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
                        <DialogTitle>{sedangDiubah ? 'Ubah tingkat harga' : 'Tambah tingkat harga'}</DialogTitle>
                        <DialogDescription>
                            Contoh: satu dus 20.000, satu pcs 2.500, dan mulai 6 pcs jadi 2.300 — tiga baris terpisah.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Produk" required error={form.errors.product_id}>
                            <NativeSelect
                                value={form.data.product_id}
                                onChange={gantiProduk}
                                placeholder="Pilih produk"
                                options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="Satuan"
                                required
                                error={form.errors.uom_id}
                                hint={satuanTerpilih ? `Harga standar ${formatRupiah(satuanTerpilih.standardPrice)}` : undefined}
                            >
                                <NativeSelect
                                    value={form.data.uom_id}
                                    onChange={gantiSatuan}
                                    placeholder={produkTerpilih ? 'Pilih satuan' : 'Pilih produk dulu'}
                                    options={(produkTerpilih?.uoms ?? []).map((u) => ({
                                        value: u.id,
                                        label: u.factor === 1 ? u.name : `${u.name} (×${u.factor})`,
                                    }))}
                                />
                            </Field>

                            <Field
                                label="Berlaku mulai qty"
                                required
                                error={form.errors.min_qty}
                                hint="Dihitung dalam satuan di sebelah kiri"
                            >
                                <Input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    value={form.data.min_qty}
                                    onChange={(e) => form.setData('min_qty', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
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
                                className="h-8 font-mono tabular"
                            />
                        </Field>

                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.active}
                                onCheckedChange={(v) => form.setData('active', v === true)}
                            />
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
