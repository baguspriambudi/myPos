import { Chip, Field, FlashMessage, NativeSelect, ServerPagination, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { PackageMinus, PackagePlus, Scale } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Penyesuaian Stok', href: '/stok/penyesuaian' },
];

interface Satuan {
    id: number;
    name: string;
    factor: number;
}

interface Produk {
    id: number;
    name: string;
    sku: string;
    isWeighted: boolean;
    baseUom: string | null;
    stock: number;
    avgCost: number;
    uoms: Satuan[];
}

interface Penyesuaian {
    id: number;
    number: string;
    date: string;
    productName: string;
    sku: string;
    uomName: string;
    type: 'IN' | 'OUT';
    qty: number;
    qtyBase: number;
    unitCost: number;
    value: number;
    reason: string;
    author: string | null;
}

export default function PenyesuaianIndex() {
    const { adjustments, products, canPost, today } = usePage().props as unknown as {
        adjustments: Paginated<Penyesuaian>;
        products: Produk[];
        canPost: boolean;
        today: string;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);

    const form = useForm({
        product_id: '' as string | number,
        uom_id: '' as string | number,
        adjustment_type: 'IN',
        qty: '',
        unit_cost: '',
        reason: '',
        adjustment_date: today,
    });

    const produkTerpilih = useMemo(() => products.find((p) => p.id === Number(form.data.product_id)) ?? null, [products, form.data.product_id]);

    const satuanTerpilih = produkTerpilih?.uoms.find((u) => u.id === Number(form.data.uom_id)) ?? null;
    const masuk = form.data.adjustment_type === 'IN';

    function bukaDialog() {
        form.setData({
            product_id: '',
            uom_id: '',
            adjustment_type: 'IN',
            qty: '',
            unit_cost: '',
            reason: '',
            adjustment_date: today,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    }

    function pilihProduk(id: string) {
        const produk = products.find((p) => p.id === Number(id));
        form.setData((data) => ({
            ...data,
            product_id: id,
            // Satuan dasar dipilih lebih dulu: penyesuaian paling sering dicatat
            // dalam satuan terkecil karena itulah wujud barang yang dihitung.
            uom_id: produk?.uoms[0]?.id ?? '',
            // Harga perolehan diisikan dari rata-rata berjalan supaya nilainya
            // tidak menyimpang tanpa disengaja; masih boleh diubah.
            unit_cost: produk && produk.avgCost > 0 ? String(produk.avgCost) : '',
        }));
    }

    function simpan() {
        form.post('/stok/penyesuaian', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    const qtyBase = satuanTerpilih && form.data.qty ? Number(form.data.qty) * satuanTerpilih.factor : 0;
    const nilai = masuk && form.data.unit_cost ? qtyBase * Number(form.data.unit_cost) : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Penyesuaian Stok" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Penyesuaian Stok"
                    subtitle="Mengubah saldo tanpa dokumen jual-beli — barang rusak, susut, hilang, atau temuan selisih."
                    action={
                        canPost && (
                            <Button size="sm" className="h-8" onClick={bukaDialog}>
                                <Scale className="h-3.5 w-3.5" /> Penyesuaian baru
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <div className="bg-muted/30 text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-2">
                    Setiap penyesuaian wajib beralasan dan tercatat bernomor — saldo tidak pernah diketik ulang begitu saja, karena riwayat pergerakan
                    inilah yang menjadi dasar perhitungan ulang HPP. Yang <span className="text-foreground font-medium">masuk</span> menyebut harga
                    perolehannya sendiri; yang <span className="text-foreground font-medium">keluar</span> memakai harga lot yang benar-benar
                    terpakai.
                </div>

                <Panel title="Riwayat Penyesuaian" description={`${adjustments.total} penyesuaian tercatat`} bodyClassName="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-44">Nomor</TableHead>
                                <TableHead className="w-24">Tanggal</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="w-24">Jenis</TableHead>
                                <TableHead className="w-28 text-right">Qty</TableHead>
                                <TableHead className="w-28 text-right">Nilai</TableHead>
                                <TableHead className="w-56">Alasan</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {adjustments.data.length === 0 && <EmptyRow colSpan={7} icon={<Scale />} message="Belum ada penyesuaian stok." />}

                            {adjustments.data.map((a) => (
                                <TableRow key={a.id}>
                                    <TableCell className="tabular font-mono text-xs font-semibold">{a.number}</TableCell>
                                    <TableCell className="text-muted-foreground tabular text-xs">{formatDate(a.date)}</TableCell>
                                    <TableCell>
                                        <span className="font-medium">{a.productName}</span>
                                        <span className="text-2xs text-muted-foreground ml-1.5 font-mono">{a.sku}</span>
                                    </TableCell>
                                    <TableCell>{a.type === 'IN' ? <Chip tone="accent">Masuk</Chip> : <Chip tone="warning">Keluar</Chip>}</TableCell>
                                    <TableCell className="tabular text-right font-mono text-xs">
                                        {a.type === 'IN' ? '+' : '−'}
                                        {formatQty(a.qty)} {a.uomName}
                                    </TableCell>
                                    <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(a.value)}</TableCell>
                                    <TableCell className="text-muted-foreground text-2xs">
                                        <span className="line-clamp-2">{a.reason}</span>
                                        {a.author && <span className="mt-0.5 block opacity-70">oleh {a.author}</span>}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={adjustments} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Penyesuaian baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Tercatat permanen sebagai pergerakan stok dan tidak dapat diubah setelah tersimpan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Jenis" required>
                                <div className="flex gap-1">
                                    {[
                                        { key: 'IN', label: 'Masuk', icon: PackagePlus },
                                        { key: 'OUT', label: 'Keluar', icon: PackageMinus },
                                    ].map((j) => (
                                        <button
                                            key={j.key}
                                            type="button"
                                            onClick={() => form.setData('adjustment_type', j.key)}
                                            className={cn(
                                                'flex h-8 flex-1 items-center justify-center gap-1 rounded-md border text-xs transition-colors',
                                                form.data.adjustment_type === j.key
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'hover:bg-accent',
                                            )}
                                        >
                                            <j.icon className="h-3.5 w-3.5" /> {j.label}
                                        </button>
                                    ))}
                                </div>
                            </Field>

                            <Field label="Tanggal" required error={form.errors.adjustment_date}>
                                <Input
                                    type="date"
                                    value={form.data.adjustment_date}
                                    onChange={(e) => form.setData('adjustment_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <Field
                            label="Produk"
                            required
                            error={form.errors.product_id}
                            hint={
                                produkTerpilih
                                    ? `Saldo sekarang ${formatQty(produkTerpilih.stock)} ${produkTerpilih.baseUom ?? ''} · rata-rata ${formatRupiah(produkTerpilih.avgCost)}`
                                    : 'Jasa tidak muncul di sini karena tidak punya stok.'
                            }
                        >
                            <NativeSelect
                                value={form.data.product_id}
                                onChange={pilihProduk}
                                placeholder="Pilih produk"
                                options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                            />
                        </Field>

                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Satuan" required error={form.errors.uom_id}>
                                <NativeSelect
                                    value={form.data.uom_id}
                                    onChange={(v) => form.setData('uom_id', v)}
                                    placeholder={produkTerpilih ? 'Pilih satuan' : 'Pilih produk dulu'}
                                    disabled={!produkTerpilih}
                                    options={(produkTerpilih?.uoms ?? []).map((u) => ({ value: u.id, label: u.name }))}
                                />
                            </Field>

                            <Field label="Kuantitas" required error={form.errors.qty}>
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    step={produkTerpilih?.isWeighted ? 0.01 : 1}
                                    min={0}
                                    value={form.data.qty}
                                    onChange={(e) => form.setData('qty', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>

                            <Field label="Harga perolehan" error={form.errors.unit_cost} hint={masuk ? 'per satuan dasar' : 'diambil dari lot'}>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    disabled={!masuk}
                                    value={masuk ? form.data.unit_cost : ''}
                                    onChange={(e) => form.setData('unit_cost', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>
                        </div>

                        {qtyBase > 0 && (
                            <p className="bg-muted/50 text-2xs text-muted-foreground rounded-md px-2.5 py-1.5">
                                Setara <span className="text-foreground font-mono font-medium">{formatQty(qtyBase)}</span> {produkTerpilih?.baseUom}{' '}
                                satuan dasar
                                {masuk && nilai > 0 && (
                                    <>
                                        {' '}
                                        senilai <span className="text-foreground font-mono font-medium">{formatRupiah(nilai)}</span>
                                    </>
                                )}
                                .
                            </p>
                        )}

                        <Field label="Alasan" required error={form.errors.reason} hint="Sebutkan sebabnya, bukan sekadar 'koreksi'.">
                            <textarea
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                rows={2}
                                placeholder="Contoh: 3 botol pecah saat penataan rak."
                                className="border-input bg-background focus:ring-ring w-full rounded-md border px-2 py-1.5 text-sm shadow-xs focus:ring-2 focus:ring-offset-2 focus:outline-none"
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan penyesuaian'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
