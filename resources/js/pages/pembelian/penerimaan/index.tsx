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
import { Ban, Package, PackagePlus, Plus, X } from 'lucide-react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pembelian', href: '#' },
    { title: 'Penerimaan Barang', href: '/penerimaan' },
];

const LABEL_SUMBER: Record<string, string> = {
    LAST_PURCHASE: 'harga beli terakhir',
    AVG_COST: 'rata-rata berjalan',
    NONE: 'belum ada acuan',
    INVOICE: 'faktur',
};

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
    uoms: Satuan[];
}

interface Pemasok {
    id: number;
    name: string;
}

interface BarisPenerimaan {
    name: string;
    sku: string;
    qty: number;
    uom: string;
    unitCost: number | null;
    costSource: string | null;
}

interface Penerimaan {
    id: number;
    number: string;
    date: string;
    supplier: string | null;
    doNumber: string | null;
    status: 'POSTED' | 'VOID';
    invoiceNumber: string | null;
    note: string | null;
    author: string | null;
    totalCost: number | null;
    items: BarisPenerimaan[];
}

interface BarisForm {
    key: number;
    product_id: string | number;
    uom_id: string | number;
    qty: string;
}

export default function PenerimaanIndex() {
    const { receipts, products, suppliers, canSeeCost, canVoid, today, errors } = usePage().props as unknown as {
        receipts: Paginated<Penerimaan>;
        products: Produk[];
        suppliers: Pemasok[];
        canSeeCost: boolean;
        canVoid: boolean;
        today: string;
        errors: Record<string, string>;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [detail, setDetail] = useState<Penerimaan | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);
    const kunciBaris = useRef(0);

    const form = useForm({
        supplier_id: '' as string | number,
        receipt_date: today,
        supplier_do_number: '',
        note: '',
        lines: [] as { product_id: number; uom_id: number; qty: number }[],
    });

    const voidForm = useForm({});

    const [rows, setRows] = useState<BarisForm[]>([]);

    function barisBaru(): BarisForm {
        kunciBaris.current += 1;
        return { key: kunciBaris.current, product_id: '', uom_id: '', qty: '' };
    }

    function bukaDialog() {
        form.setData({
            supplier_id: '',
            receipt_date: today,
            supplier_do_number: '',
            note: '',
            lines: [],
        });
        form.clearErrors();
        setRows([barisBaru()]);
        setDialogTerbuka(true);
    }

    function tambahBaris() {
        setRows((prev) => [...prev, barisBaru()]);
    }

    function hapusBaris(key: number) {
        setRows((prev) => prev.filter((r) => r.key !== key));
    }

    function pilihProdukBaris(key: number, productId: string) {
        const produk = products.find((p) => p.id === Number(productId));
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, product_id: productId, uom_id: produk?.uoms[0]?.id ?? '' } : r)));
    }

    function ubahBaris(key: number, field: 'uom_id' | 'qty', value: string) {
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, [field]: value } : r)));
    }

    const barisValid = rows.filter((r) => r.product_id && r.uom_id && r.qty);

    function simpan() {
        form.transform((data) => ({
            ...data,
            lines: barisValid.map((r) => ({
                product_id: Number(r.product_id),
                uom_id: Number(r.uom_id),
                qty: Number(r.qty),
            })),
        }));
        form.post('/penerimaan', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    function bukaBatal() {
        voidForm.clearErrors();
        setBatalTerbuka(true);
    }

    function batalkan() {
        if (!detail) return;
        voidForm.post(`/penerimaan/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Penerimaan Barang" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Penerimaan Barang"
                    subtitle="Mencatat kedatangan barang dan menambah stok. Harganya menyusul lewat faktur pemasok."
                    action={
                        <Button size="sm" className="h-8" onClick={bukaDialog}>
                            <PackagePlus className="h-3.5 w-3.5" /> Penerimaan baru
                        </Button>
                    }
                />

                <FlashMessage />

                <div className="bg-muted/30 text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-2">
                    Catat qty fisik yang benar-benar datang — harga tidak perlu diisi di sini. Sistem mengisi harga sementara secara otomatis dan
                    akan disesuaikan begitu faktur pemasok datang.
                </div>

                <Panel title="Riwayat Penerimaan" description={`${receipts.total} penerimaan tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Pemasok</TableHead>
                                    <TableHead className="w-32">No. Surat Jalan</TableHead>
                                    <TableHead className="w-28">Faktur</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                    {canSeeCost && <TableHead className="w-28 text-right">Nilai</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receipts.data.length === 0 && (
                                    <EmptyRow colSpan={canSeeCost ? 7 : 6} icon={<Package />} message="Belum ada penerimaan barang." />
                                )}

                                {receipts.data.map((r) => (
                                    <TableRow
                                        key={r.id}
                                        onClick={() => setDetail(r)}
                                        className={cn('cursor-pointer', r.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{r.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(r.date)}</TableCell>
                                        <TableCell className="text-xs">{r.supplier ?? '—'}</TableCell>
                                        <TableCell className="text-2xs text-muted-foreground">{r.doNumber ?? '—'}</TableCell>
                                        <TableCell>
                                            {r.invoiceNumber ? (
                                                <Chip tone="accent">{r.invoiceNumber}</Chip>
                                            ) : (
                                                <Chip tone="warning">Belum</Chip>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {r.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                        {canSeeCost && (
                                            <TableCell className="tabular text-right font-mono text-xs">
                                                {r.totalCost !== null ? formatRupiah(r.totalCost) : '—'}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={receipts} />
                </Panel>
            </div>

            {/* ============================================== dialog penerimaan baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Penerimaan baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Stok bertambah begitu tersimpan. Harga tidak diisi di sini — sistem mengisinya sementara.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Pemasok" error={form.errors.supplier_id} className="col-span-2">
                                <NativeSelect
                                    value={form.data.supplier_id}
                                    onChange={(v) => form.setData('supplier_id', v)}
                                    placeholder="Tanpa pemasok / belum diketahui"
                                    options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                                />
                            </Field>

                            <Field label="Tanggal terima" required error={form.errors.receipt_date}>
                                <Input
                                    type="date"
                                    value={form.data.receipt_date}
                                    onChange={(e) => form.setData('receipt_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <Field label="No. Surat Jalan" error={form.errors.supplier_do_number}>
                            <Input
                                value={form.data.supplier_do_number}
                                onChange={(e) => form.setData('supplier_do_number', e.target.value)}
                                className="h-8"
                                placeholder="Opsional"
                            />
                        </Field>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="label-eyebrow">Barang diterima</span>
                                <Button type="button" variant="outline" size="sm" className="h-7" onClick={tambahBaris}>
                                    <Plus className="h-3.5 w-3.5" /> Tambah baris
                                </Button>
                            </div>

                            {rows.length === 0 && (
                                <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-4 text-center">
                                    Belum ada baris.
                                </p>
                            )}

                            <div className="space-y-2">
                                {rows.map((row) => {
                                    const produk = products.find((p) => p.id === Number(row.product_id)) ?? null;
                                    return (
                                        <div key={row.key} className="flex items-end gap-2 rounded-md border p-2">
                                            <div className="min-w-0 flex-1">
                                                <NativeSelect
                                                    value={row.product_id}
                                                    onChange={(v) => pilihProdukBaris(row.key, v)}
                                                    placeholder="Pilih produk"
                                                    options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                                                />
                                            </div>
                                            <div className="w-28">
                                                <NativeSelect
                                                    value={row.uom_id}
                                                    onChange={(v) => ubahBaris(row.key, 'uom_id', v)}
                                                    placeholder={produk ? 'Satuan' : '—'}
                                                    disabled={!produk}
                                                    options={(produk?.uoms ?? []).map((u) => ({ value: u.id, label: u.name }))}
                                                />
                                            </div>
                                            <div className="w-24">
                                                <Input
                                                    type="number"
                                                    inputMode="decimal"
                                                    step={produk?.isWeighted ? 0.01 : 1}
                                                    min={0}
                                                    value={row.qty}
                                                    onChange={(e) => ubahBaris(row.key, 'qty', e.target.value)}
                                                    placeholder="Qty"
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground hover:text-destructive h-8 w-8"
                                                onClick={() => hapusBaris(row.key)}
                                                aria-label="Hapus baris"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>

                            {form.errors.lines && <p className="text-2xs text-destructive font-medium">{form.errors.lines}</p>}
                        </div>

                        <Field label="Catatan" error={form.errors.note}>
                            <textarea
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                rows={2}
                                placeholder="Opsional"
                                className="border-input bg-background focus:ring-ring w-full rounded-md border px-2 py-1.5 text-sm shadow-xs focus:ring-2 focus:ring-offset-2 focus:outline-none"
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing || barisValid.length === 0}>
                            {form.processing ? 'Menyimpan...' : 'Simpan penerimaan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================= dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Package className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · {detail.supplier ?? 'Tanpa pemasok'}
                                    {detail.doNumber && <> · SJ {detail.doNumber}</>}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            {detail.invoiceNumber && (
                                <p className="bg-muted/50 text-2xs rounded-md px-2.5 py-1.5">
                                    Sudah difakturkan sebagai <span className="font-medium">{detail.invoiceNumber}</span>.
                                </p>
                            )}

                            <ul className="divide-y border-y">
                                {detail.items.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                {formatQty(l.qty)} {l.uom}
                                                {l.sku && <> · {l.sku}</>}
                                            </span>
                                        </span>
                                        {canSeeCost && l.unitCost !== null && (
                                            <span className="tabular shrink-0 text-right font-mono">
                                                {formatRupiah(l.unitCost)}
                                                {l.costSource && (
                                                    <span className="text-2xs text-muted-foreground block">{LABEL_SUMBER[l.costSource]}</span>
                                                )}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>

                            {canSeeCost && detail.totalCost !== null && (
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <span>Nilai penerimaan</span>
                                    <span className="tabular font-mono">{formatRupiah(detail.totalCost)}</span>
                                </div>
                            )}

                            {detail.note && (
                                <p className="text-muted-foreground text-2xs">
                                    <span className="font-medium">Catatan:</span> {detail.note}
                                </p>
                            )}

                            {detail.author && <p className="text-muted-foreground text-2xs">oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {canVoid && detail?.status === 'POSTED' && !detail.invoiceNumber && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Penerimaan
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================== dialog batal */}
            <Dialog open={batalTerbuka} onOpenChange={setBatalTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {detail?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Stok yang sudah masuk akan ditarik kembali. Penerimaan tetap tersimpan dan nomornya tidak dipakai ulang — pembatalan
                            tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.receipt && <p className="text-2xs text-destructive font-medium">{errors.receipt}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={voidForm.processing}>
                            {voidForm.processing ? 'Membatalkan...' : 'Ya, batalkan penerimaan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
