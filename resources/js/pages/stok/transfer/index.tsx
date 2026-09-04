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
import { ArrowRightLeft, Ban, Plus, X } from 'lucide-react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Transfer Antar-Lokasi', href: '/stok/transfer' },
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
    stock: number;
    uoms: Satuan[];
}

interface Lokasi {
    id: number;
    name: string;
}

interface BarisTransfer {
    name: string;
    sku: string;
    qty: number;
    uom: string;
    unitCost: number | null;
}

interface Transfer {
    id: number;
    number: string;
    date: string;
    fromBranchId: number;
    fromBranch: string;
    toBranch: string;
    status: 'POSTED' | 'VOID';
    author: string | null;
    items: BarisTransfer[];
}

interface BarisForm {
    key: number;
    product_id: string | number;
    uom_id: string | number;
    qty: string;
}

export default function TransferIndex() {
    const { transfers, products, destinations, activeBranchId, canSeeCost, canVoid, today, errors } = usePage().props as unknown as {
        transfers: Paginated<Transfer>;
        products: Produk[];
        destinations: Lokasi[];
        activeBranchId: number | null;
        canSeeCost: boolean;
        canVoid: boolean;
        today: string;
        errors: Record<string, string>;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [detail, setDetail] = useState<Transfer | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);
    const kunciBaris = useRef(0);

    const form = useForm({
        to_branch_id: '' as string | number,
        transaction_date: today,
        lines: [] as { product_id: number; uom_id: number; qty: number }[],
    });

    const voidForm = useForm({});

    const [rows, setRows] = useState<BarisForm[]>([]);

    function barisBaru(): BarisForm {
        kunciBaris.current += 1;
        return { key: kunciBaris.current, product_id: '', uom_id: '', qty: '' };
    }

    function bukaDialog() {
        form.setData({ to_branch_id: '', transaction_date: today, lines: [] });
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
        form.post('/stok/transfer', {
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
        voidForm.post(`/stok/transfer/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transfer Antar-Lokasi" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Transfer Antar-Lokasi"
                    subtitle="Memindahkan stok dari lokasi aktif ke lokasi lain — langsung terpasang, tanpa status dalam perjalanan."
                    action={
                        destinations.length > 0 && (
                            <Button size="sm" className="h-8" onClick={bukaDialog}>
                                <ArrowRightLeft className="h-3.5 w-3.5" /> Transfer baru
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel title="Riwayat Transfer" description={`${transfers.total} transfer tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Dari</TableHead>
                                    <TableHead>Ke</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transfers.data.length === 0 && (
                                    <EmptyRow colSpan={5} icon={<ArrowRightLeft />} message="Belum ada transfer antar-lokasi." />
                                )}

                                {transfers.data.map((t) => (
                                    <TableRow
                                        key={t.id}
                                        onClick={() => setDetail(t)}
                                        className={cn('cursor-pointer', t.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{t.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(t.date)}</TableCell>
                                        <TableCell className="text-xs">{t.fromBranch}</TableCell>
                                        <TableCell className="text-xs">{t.toBranch}</TableCell>
                                        <TableCell>
                                            {t.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={transfers} />
                </Panel>
            </div>

            {/* ================================================== dialog transfer baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Transfer baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Stok langsung berpindah begitu tersimpan — kedua lokasi harus punya periode pembukuan yang masih terbuka.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Lokasi tujuan" required error={form.errors.to_branch_id}>
                                <NativeSelect
                                    value={form.data.to_branch_id}
                                    onChange={(v) => form.setData('to_branch_id', v)}
                                    placeholder="Pilih lokasi"
                                    options={destinations.map((d) => ({ value: d.id, label: d.name }))}
                                />
                            </Field>

                            <Field label="Tanggal transfer" required error={form.errors.transaction_date}>
                                <Input
                                    type="date"
                                    value={form.data.transaction_date}
                                    onChange={(e) => form.setData('transaction_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="label-eyebrow">Barang ditransfer</span>
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
                                        <div key={row.key} className="rounded-md border p-2">
                                            <div className="flex items-end gap-2">
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
                                            {produk && (
                                                <p className="text-2xs text-muted-foreground mt-1">
                                                    Stok di lokasi aktif: {formatQty(produk.stock)}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            {form.errors.lines && <p className="text-2xs text-destructive font-medium">{form.errors.lines}</p>}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing || barisValid.length === 0}>
                            {form.processing ? 'Menyimpan...' : 'Simpan transfer'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================= dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <ArrowRightLeft className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · {detail.fromBranch} → {detail.toBranch}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            <ul className="divide-y border-y">
                                {detail.items.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                {formatQty(l.qty)} {l.uom}
                                            </span>
                                        </span>
                                        {canSeeCost && l.unitCost !== null && (
                                            <span className="tabular shrink-0 font-mono">{formatRupiah(l.unitCost)}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>

                            {detail.author && <p className="text-muted-foreground text-2xs">oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {canVoid && detail?.status === 'POSTED' && detail.fromBranchId === activeBranchId && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Transfer
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
                            Stok kembali ke lokasi asal dan ditarik dari lokasi tujuan. Ditolak bila barangnya sudah terpakai di lokasi tujuan.
                            Transfer tetap tersimpan dan nomornya tidak dipakai ulang — pembatalan tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.transfer && <p className="text-2xs text-destructive font-medium">{errors.transfer}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={voidForm.processing}>
                            {voidForm.processing ? 'Membatalkan...' : 'Ya, batalkan transfer'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
