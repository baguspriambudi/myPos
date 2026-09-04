import { Chip, Field, FlashMessage, ServerPagination, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, Search, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Penjualan', href: '#' },
    { title: 'Retur Penjualan', href: '/retur/penjualan' },
];

interface BarisRetur {
    name: string;
    sku: string;
    qty: number;
    uom: string;
    costAtReturn: number | null;
    refundAmount: number;
}

interface Retur {
    id: number;
    number: string;
    date: string;
    saleNumber: string | null;
    status: 'POSTED' | 'VOID';
    refundTotal: number;
    author: string | null;
    items: BarisRetur[];
}

interface SaleLookupItem {
    saleItemId: number;
    name: string;
    sku: string;
    uom: string;
    qty: number;
    sisa: number;
}

interface SaleLookup {
    found: boolean;
    id?: number;
    number?: string;
    date?: string;
    customer?: string | null;
    items?: SaleLookupItem[];
}

export default function ReturPenjualanIndex() {
    const { returns, saleLookup, query, canInput, canSeeCost, canVoid, today, errors } = usePage().props as unknown as {
        returns: Paginated<Retur>;
        saleLookup: SaleLookup | null;
        query: string;
        canInput: boolean;
        canSeeCost: boolean;
        canVoid: boolean;
        today: string;
        errors: Record<string, string>;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [cariInput, setCariInput] = useState('');
    const [qtyRetur, setQtyRetur] = useState<Record<number, string>>({});
    const [detail, setDetail] = useState<Retur | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);

    const form = useForm({
        sale_id: '' as string | number,
        transaction_date: today,
        lines: [] as { sale_item_id: number; qty: number }[],
    });

    const voidForm = useForm({});

    function bukaDialog() {
        setCariInput('');
        setQtyRetur({});
        form.setData({ sale_id: '', transaction_date: today, lines: [] });
        form.clearErrors();
        setDialogTerbuka(true);
    }

    function cari() {
        router.get('/retur/penjualan', { cari: cariInput }, { preserveState: true, preserveScroll: true, only: ['saleLookup', 'query'] });
    }

    useEffect(() => {
        if (saleLookup?.found) {
            form.setData('sale_id', saleLookup.id ?? '');
            setQtyRetur({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [saleLookup?.id]);

    function simpan() {
        const lines = Object.entries(qtyRetur)
            .filter(([, v]) => v && Number(v) > 0)
            .map(([saleItemId, v]) => ({ sale_item_id: Number(saleItemId), qty: Number(v) }));

        form.transform((data) => ({ ...data, lines }));
        form.post('/retur/penjualan', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    const barisValid = Object.values(qtyRetur).filter((v) => v && Number(v) > 0).length;

    function bukaBatal() {
        voidForm.clearErrors();
        setBatalTerbuka(true);
    }

    function batalkan() {
        if (!detail) return;
        voidForm.post(`/retur/penjualan/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Retur Penjualan" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Retur Penjualan"
                    subtitle="Barang kembali dari pelanggan — stok bertambah lagi dengan HPP asal transaksinya."
                    action={
                        canInput && (
                            <Button size="sm" className="h-8" onClick={bukaDialog}>
                                <Undo2 className="h-3.5 w-3.5" /> Retur baru
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel title="Riwayat Retur" description={`${returns.total} retur tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead className="w-40">Nota Asal</TableHead>
                                    <TableHead className="w-28 text-right">Refund</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {returns.data.length === 0 && <EmptyRow colSpan={5} icon={<Undo2 />} message="Belum ada retur penjualan." />}

                                {returns.data.map((r) => (
                                    <TableRow
                                        key={r.id}
                                        onClick={() => setDetail(r)}
                                        className={cn('cursor-pointer', r.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{r.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(r.date)}</TableCell>
                                        <TableCell className="font-mono text-xs">{r.saleNumber ?? '—'}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(r.refundTotal)}</TableCell>
                                        <TableCell>
                                            {r.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={returns} />
                </Panel>
            </div>

            {/* =================================================== dialog retur baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Retur baru</DialogTitle>
                        <DialogDescription className="text-2xs">Cari nota asal, lalu tentukan barang dan qty yang dikembalikan.</DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="flex gap-2">
                            <Input
                                value={cariInput}
                                onChange={(e) => setCariInput(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && cari()}
                                placeholder="Nomor nota..."
                                className="h-8"
                                autoFocus
                            />
                            <Button type="button" variant="outline" size="sm" className="h-8" onClick={cari}>
                                <Search className="h-3.5 w-3.5" /> Cari
                            </Button>
                        </div>

                        {form.errors.sale_id && <p className="text-2xs text-destructive font-medium">{form.errors.sale_id}</p>}

                        {saleLookup && !saleLookup.found && (
                            <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-4 text-center">
                                Nota &quot;{query}&quot; tidak ditemukan, bukan milik lokasi ini, atau belum diposting.
                            </p>
                        )}

                        {saleLookup?.found && (
                            <>
                                <div className="bg-muted/50 text-2xs rounded-md px-2.5 py-1.5">
                                    <span className="font-medium">{saleLookup.number}</span> · {saleLookup.date && formatDate(saleLookup.date)} ·{' '}
                                    {saleLookup.customer ?? 'Tanpa pelanggan'}
                                </div>

                                <div className="space-y-1.5">
                                    {(saleLookup.items ?? []).map((it) => (
                                        <div key={it.saleItemId} className="flex items-center gap-2 rounded-md border p-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-xs">{it.name}</p>
                                                <p className="text-2xs text-muted-foreground font-mono">
                                                    Terjual {formatQty(it.qty)} {it.uom} · sisa bisa diretur {formatQty(it.sisa)} {it.uom}
                                                </p>
                                            </div>
                                            <div className="w-24">
                                                <Input
                                                    type="number"
                                                    inputMode="decimal"
                                                    min={0}
                                                    max={it.sisa}
                                                    step="any"
                                                    disabled={it.sisa <= 0}
                                                    value={qtyRetur[it.saleItemId] ?? ''}
                                                    onChange={(e) => setQtyRetur((prev) => ({ ...prev, [it.saleItemId]: e.target.value }))}
                                                    placeholder="Qty"
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {form.errors.lines && <p className="text-2xs text-destructive font-medium">{form.errors.lines}</p>}
                            </>
                        )}

                        <Field label="Tanggal retur" required error={form.errors.transaction_date}>
                            <Input
                                type="date"
                                value={form.data.transaction_date}
                                onChange={(e) => form.setData('transaction_date', e.target.value)}
                                className="h-8"
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing || !saleLookup?.found || barisValid === 0}>
                            {form.processing ? 'Menyimpan...' : 'Simpan retur'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ======================================================== dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Undo2 className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · nota asal {detail.saleNumber ?? '—'}
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
                                                {canSeeCost && l.costAtReturn !== null && <> · HPP {formatRupiah(l.costAtReturn)}</>}
                                            </span>
                                        </span>
                                        <span className="tabular shrink-0 font-mono">{formatRupiah(l.refundAmount)}</span>
                                    </li>
                                ))}
                            </ul>

                            <div className="flex justify-between border-t pt-1 font-medium">
                                <span>Total refund</span>
                                <span className="tabular font-mono">{formatRupiah(detail.refundTotal)}</span>
                            </div>

                            {detail.author && <p className="text-muted-foreground text-2xs">oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {canVoid && detail?.status === 'POSTED' && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Retur
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================= dialog batal */}
            <Dialog open={batalTerbuka} onOpenChange={setBatalTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {detail?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Stok yang sudah kembali akan ditarik lagi. Retur tetap tersimpan dan nomornya tidak dipakai ulang — pembatalan tidak
                            dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.return && <p className="text-2xs text-destructive font-medium">{errors.return}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={voidForm.processing}>
                            {voidForm.processing ? 'Membatalkan...' : 'Ya, batalkan retur'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
