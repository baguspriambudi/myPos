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
import { CheckCircle2, ClipboardList, Plus, X } from 'lucide-react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Stock Opname', href: '/stok/opname' },
];

const LABEL_STATUS: Record<string, string> = {
    DRAFT: 'Draf',
    APPLIED: 'Diterapkan',
    CANCELLED: 'Dibatalkan',
};

const TONE_STATUS: Record<string, 'neutral' | 'accent' | 'warning'> = {
    DRAFT: 'warning',
    APPLIED: 'accent',
    CANCELLED: 'neutral',
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
    baseUom: string | null;
    stock: number;
    uoms: Satuan[];
}

interface BarisOpname {
    name: string;
    sku: string;
    systemQty: number;
    actualQty: number;
    uom: string;
    differenceQtyBase: number;
    differenceValue: number | null;
}

interface Opname {
    id: number;
    number: string;
    date: string;
    status: 'DRAFT' | 'APPLIED' | 'CANCELLED';
    author: string | null;
    appliedBy: string | null;
    items: BarisOpname[];
}

interface BarisForm {
    key: number;
    product_id: string | number;
    uom_id: string | number;
    actual_qty: string;
}

export default function OpnameIndex() {
    const { opnames, products, canTerapkan, canSeeCost, today } = usePage().props as unknown as {
        opnames: Paginated<Opname>;
        products: Produk[];
        canTerapkan: boolean;
        canSeeCost: boolean;
        today: string;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [detail, setDetail] = useState<Opname | null>(null);
    const kunciBaris = useRef(0);

    const form = useForm({
        opname_date: today,
        lines: [] as { product_id: number; uom_id: number; actual_qty: number }[],
    });

    const terapkanForm = useForm({});
    const batalForm = useForm({});

    const [rows, setRows] = useState<BarisForm[]>([]);

    function barisBaru(): BarisForm {
        kunciBaris.current += 1;
        return { key: kunciBaris.current, product_id: '', uom_id: '', actual_qty: '' };
    }

    function bukaDialog() {
        form.setData({ opname_date: today, lines: [] });
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

    function ubahBaris(key: number, field: 'uom_id' | 'actual_qty', value: string) {
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, [field]: value } : r)));
    }

    const barisValid = rows.filter((r) => r.product_id && r.uom_id && r.actual_qty !== '');

    function simpan() {
        form.transform((data) => ({
            ...data,
            lines: barisValid.map((r) => ({
                product_id: Number(r.product_id),
                uom_id: Number(r.uom_id),
                actual_qty: Number(r.actual_qty),
            })),
        }));
        form.post('/stok/opname', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    function terapkan() {
        if (!detail) return;
        terapkanForm.post(`/stok/opname/${detail.id}/terapkan`, {
            preserveScroll: true,
            onSuccess: () => setDetail(null),
        });
    }

    function batalkanDraf() {
        if (!detail) return;
        batalForm.post(`/stok/opname/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => setDetail(null),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Opname" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Stock Opname"
                    subtitle="Catat hitungan fisik sebagai draf, lalu terapkan untuk mengubah saldo lewat penyesuaian stok."
                    action={
                        <Button size="sm" className="h-8" onClick={bukaDialog}>
                            <ClipboardList className="h-3.5 w-3.5" /> Hitungan baru
                        </Button>
                    }
                />

                <FlashMessage />

                <Panel title="Riwayat Opname" description={`${opnames.total} lembar hitungan`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Baris</TableHead>
                                    <TableHead className="w-28">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {opnames.data.length === 0 && <EmptyRow colSpan={4} icon={<ClipboardList />} message="Belum ada stock opname." />}

                                {opnames.data.map((o) => (
                                    <TableRow
                                        key={o.id}
                                        onClick={() => setDetail(o)}
                                        className={cn('cursor-pointer', o.status === 'CANCELLED' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{o.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(o.date)}</TableCell>
                                        <TableCell className="text-2xs text-muted-foreground">
                                            {o.items.length} produk ·{' '}
                                            {o.items.filter((i) => i.differenceQtyBase !== 0).length} selisih
                                        </TableCell>
                                        <TableCell>
                                            <Chip tone={TONE_STATUS[o.status]}>{LABEL_STATUS[o.status]}</Chip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={opnames} />
                </Panel>
            </div>

            {/* =================================================== dialog hitungan baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Hitungan baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Tersimpan sebagai draf dulu — saldo baru berubah setelah diterapkan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Tanggal hitung" required error={form.errors.opname_date}>
                            <Input
                                type="date"
                                value={form.data.opname_date}
                                onChange={(e) => form.setData('opname_date', e.target.value)}
                                className="h-8"
                            />
                        </Field>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="label-eyebrow">Hasil hitungan fisik</span>
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
                                                        value={row.actual_qty}
                                                        onChange={(e) => ubahBaris(row.key, 'actual_qty', e.target.value)}
                                                        placeholder="Hasil"
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
                                                    Saldo sistem saat ini: {formatQty(produk.stock)} {produk.baseUom}
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
                            {form.processing ? 'Menyimpan...' : 'Simpan sebagai draf'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ======================================================== dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <ClipboardList className="h-4 w-4" />
                            {detail?.number}
                            {detail && <Chip tone={TONE_STATUS[detail.status]}>{LABEL_STATUS[detail.status]}</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">{detail && formatDate(detail.date)}</DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            <ul className="divide-y border-y">
                                {detail.items.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                sistem {formatQty(l.systemQty)} {l.uom} · fisik {formatQty(l.actualQty)} {l.uom}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span
                                                className={cn(
                                                    'tabular block font-mono',
                                                    l.differenceQtyBase > 0 && 'text-success',
                                                    l.differenceQtyBase < 0 && 'text-destructive',
                                                )}
                                            >
                                                {l.differenceQtyBase > 0 ? '+' : ''}
                                                {formatQty(l.differenceQtyBase)}
                                            </span>
                                            {canSeeCost && l.differenceValue !== null && l.differenceValue !== 0 && (
                                                <span className="text-2xs text-muted-foreground font-mono">{formatRupiah(l.differenceValue)}</span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            {detail.appliedBy && <p className="text-muted-foreground text-2xs">diterapkan oleh {detail.appliedBy}</p>}
                            {!detail.appliedBy && detail.author && <p className="text-muted-foreground text-2xs">dihitung oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {detail?.status === 'DRAFT' && (
                            <div className="flex gap-2">
                                <Button variant="destructive" size="sm" onClick={batalkanDraf} disabled={batalForm.processing}>
                                    {batalForm.processing ? 'Membatalkan...' : 'Batalkan draf'}
                                </Button>
                                {canTerapkan && (
                                    <Button size="sm" onClick={terapkan} disabled={terapkanForm.processing}>
                                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                        {terapkanForm.processing ? 'Menerapkan...' : 'Terapkan'}
                                    </Button>
                                )}
                            </div>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
