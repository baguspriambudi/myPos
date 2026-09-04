import { Chip, FlashMessage, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, Receipt } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Penjualan', href: '#' },
    { title: 'Nota Penjualan', href: '/penjualan' },
];

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

const SARINGAN: { key: string; label: string }[] = [
    { key: 'ALL', label: 'Semua' },
    { key: 'POSTED', label: 'Diposting' },
    { key: 'VOID', label: 'Dibatalkan' },
];

interface BarisNota {
    name: string;
    sku: string;
    qty: number;
    uom: string;
    unitPrice: number;
    lineTotal: number;
    cogs: number;
}

interface Nota {
    id: number;
    number: string;
    date: string;
    customer: string | null;
    cashier: string | null;
    status: 'POSTED' | 'VOID' | 'DRAFT';
    paymentType: string | null;
    netTotal: number;
    rounding: number;
    paidTotal: number;
    cogs: number;
    voidReason: string | null;
    items: BarisNota[];
}

export default function PenjualanIndex() {
    const { sales, filters, summary, canVoid, errors } = usePage().props as unknown as {
        sales: Paginated<Nota>;
        filters: { q: string; status: string };
        summary: { count: number; net: number; cogs: number; rounding: number };
        canVoid: boolean;
        // Penolakan dari SaleVoider datang dengan kunci "sale" — di luar daftar
        // field formulir, jadi dibaca dari props halaman.
        errors: Record<string, string>;
    };

    const [detail, setDetail] = useState<Nota | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);

    const form = useForm({ void_reason: '' });

    function gantiStatus(status: string) {
        router.get('/penjualan', { status, q: filters.q || undefined }, { preserveState: true, preserveScroll: true });
    }

    function bukaBatal() {
        form.setData('void_reason', '');
        form.clearErrors();
        setBatalTerbuka(true);
    }

    function batalkan() {
        if (!detail) return;
        form.post(`/penjualan/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    const kartu = [
        { label: 'Nota diposting', value: String(summary.count) },
        { label: 'Nilai penjualan', value: formatRupiah(summary.net) },
        { label: 'HPP', value: formatRupiah(summary.cogs) },
        { label: 'Selisih pembulatan', value: formatRupiah(summary.rounding) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nota Penjualan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader title="Nota Penjualan" subtitle="Seluruh nota di lokasi aktif. Nota yang dibatalkan tetap tersimpan beserta nomornya." />

                <FlashMessage />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {kartu.map((k) => (
                        <div key={k.label} className="bg-card rounded-lg border px-4 py-3">
                            <p className="label-eyebrow">{k.label}</p>
                            <p className="tabular mt-1 font-mono text-xl font-semibold">{k.value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <ServerSearch
                        routeUrl="/penjualan"
                        initial={filters.q}
                        placeholder="Cari nomor nota atau pelanggan..."
                        className="w-full sm:max-w-xs"
                    />
                    <div className="flex flex-wrap gap-1">
                        {SARINGAN.map((f) => (
                            <button
                                key={f.key}
                                onClick={() => gantiStatus(f.key)}
                                className={cn(
                                    'rounded-md border px-2.5 py-1 text-xs transition-colors',
                                    filters.status === f.key ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent',
                                )}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>

                <Panel title="Daftar Nota" description={`${sales.total} nota tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-48">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Pelanggan</TableHead>
                                    <TableHead className="w-24">Bayar</TableHead>
                                    <TableHead className="w-28 text-right">Nilai</TableHead>
                                    <TableHead className="w-28 text-right">HPP</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sales.data.length === 0 && <EmptyRow colSpan={7} icon={<Receipt />} message="Belum ada nota penjualan." />}

                                {sales.data.map((s) => (
                                    <TableRow
                                        key={s.id}
                                        onClick={() => setDetail(s)}
                                        className={cn('cursor-pointer', s.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{s.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(s.date)}</TableCell>
                                        <TableCell className="text-xs">
                                            {s.customer ?? '—'}
                                            {s.cashier && <span className="text-2xs text-muted-foreground block">kasir {s.cashier}</span>}
                                        </TableCell>
                                        <TableCell className="text-2xs">{LABEL_METODE[s.paymentType ?? ''] ?? '—'}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(s.netTotal)}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-right font-mono text-xs">
                                            {formatRupiah(s.cogs)}
                                        </TableCell>
                                        <TableCell>
                                            {s.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={sales} />
                </Panel>
            </div>

            {/* ==================================================== detail nota */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Receipt className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · {detail.customer ?? 'Tanpa pelanggan'} ·{' '}
                                    {LABEL_METODE[detail.paymentType ?? ''] ?? '—'}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            {detail.status === 'VOID' && detail.voidReason && (
                                <p className="bg-warning/10 text-2xs rounded-md px-2.5 py-1.5">
                                    <span className="font-medium">Alasan pembatalan:</span> {detail.voidReason}
                                </p>
                            )}

                            <ul className="divide-y border-y">
                                {detail.items.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                {formatQty(l.qty)} {l.uom} × {formatRupiah(l.unitPrice)} · HPP {formatRupiah(l.cogs)}
                                            </span>
                                        </span>
                                        <span className="tabular shrink-0 font-mono">{formatRupiah(l.lineTotal)}</span>
                                    </li>
                                ))}
                            </ul>

                            <dl className="space-y-1">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Nilai penjualan</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.netTotal)}</dd>
                                </div>
                                {detail.rounding !== 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Pembulatan</dt>
                                        <dd className="tabular font-mono">{formatRupiah(detail.rounding)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Dibayar</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.paidTotal)}</dd>
                                </div>
                                <div className="text-muted-foreground flex justify-between">
                                    <dt>HPP nota</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.cogs)}</dd>
                                </div>
                            </dl>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {canVoid && detail?.status === 'POSTED' && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Nota
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================== dialog batal */}
            <Dialog open={batalTerbuka} onOpenChange={setBatalTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {detail?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Stok kembali ke lot asalnya dan pembayarannya ikut dibatalkan. Notanya tetap tersimpan dan nomornya tidak dipakai ulang —
                            pembatalan tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <label className="label-eyebrow" htmlFor="alasan-batal">
                            Alasan pembatalan
                        </label>
                        <textarea
                            id="alasan-batal"
                            autoFocus
                            rows={3}
                            value={form.data.void_reason}
                            onChange={(e) => form.setData('void_reason', e.target.value)}
                            placeholder="Contoh: pelanggan membatalkan pembelian sebelum barang dibawa."
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-2 py-1.5 text-sm shadow-xs focus:ring-2 focus:ring-offset-2 focus:outline-none"
                        />
                        {(form.errors.void_reason || errors?.sale) && (
                            <p className="text-2xs text-destructive font-medium">{form.errors.void_reason ?? errors.sale}</p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={form.processing}>
                            {form.processing ? 'Membatalkan...' : 'Ya, batalkan nota'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
