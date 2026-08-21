import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Ban, Printer, Receipt } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    EmptyRow,
    PageHeader,
    Panel,
    SearchInput,
    StatusBadge,
    TablePagination,
    formatDate,
    formatQty,
    formatRupiah,
    usePagination,
} from '@/components/pos-ui';
import { sales, type DocStatus, type Sale } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transaksi', href: '#' },
    { title: 'Penjualan', href: '/penjualan' },
];

const PAYMENT_LABEL: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

const FILTERS: { key: DocStatus | 'ALL'; label: string }[] = [
    { key: 'ALL', label: 'Semua' },
    { key: 'POSTED', label: 'Diposting' },
    { key: 'DRAFT', label: 'Draft' },
    { key: 'VOID', label: 'Dibatalkan' },
];

export default function PenjualanIndex() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<DocStatus | 'ALL'>('ALL');
    const [detail, setDetail] = useState<Sale | null>(null);

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return sales.filter((s) => {
            const cocokStatus = status === 'ALL' || s.status === status;
            const cocokCari =
                !q || s.number.toLowerCase().includes(q) || (s.customer ?? '').toLowerCase().includes(q);
            return cocokStatus && cocokCari;
        });
    }, [search, status]);

    const pagination = usePagination(visible, 10);

    const posted = sales.filter((s) => s.status === 'POSTED');
    const totalOmzet = posted.reduce((sum, s) => sum + s.netTotal, 0);
    const totalCogs = posted.reduce((sum, s) => sum + s.cogs, 0);
    const totalPembulatan = posted.reduce((sum, s) => sum + s.roundingAmount, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Penjualan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Penjualan"
                    subtitle="Seluruh nota penjualan pada periode berjalan. Nota yang dibatalkan tetap tersimpan beserta nomornya."
                />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Nota diposting</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{posted.length}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Nilai penjualan</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatRupiah(totalOmzet)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">HPP</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatRupiah(totalCogs)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Selisih pembulatan</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">
                            {formatRupiah(totalPembulatan)}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <SearchInput
                        value={search}
                        onChange={setSearch}
                        placeholder="Cari nomor nota atau pelanggan..."
                        className="w-full sm:max-w-xs"
                    />
                    <div className="flex flex-wrap gap-1">
                        {FILTERS.map((f) => (
                            <button
                                key={f.key}
                                onClick={() => setStatus(f.key)}
                                className={cn(
                                    'rounded-md border px-2.5 py-1 text-xs transition-colors',
                                    status === f.key
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card hover:bg-accent',
                                )}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>

                <Panel title="Daftar Nota" description={`${visible.length} nota ditampilkan`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Nomor</TableHead>
                                    <TableHead className="label-eyebrow">Tanggal</TableHead>
                                    <TableHead className="label-eyebrow">Pelanggan</TableHead>
                                    <TableHead className="label-eyebrow">Bayar</TableHead>
                                    <TableHead className="label-eyebrow text-right">Item</TableHead>
                                    <TableHead className="label-eyebrow text-right">Diterima</TableHead>
                                    <TableHead className="label-eyebrow text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<Receipt />} message="Tidak ada nota yang cocok." />
                                )}
                                {pagination.paged.map((s) => (
                                    <TableRow
                                        key={s.id}
                                        className={cn('cursor-pointer', s.status === 'VOID' && 'text-muted-foreground')}
                                        onClick={() => setDetail(s)}
                                    >
                                        <TableCell className="font-mono text-xs">{s.number}</TableCell>
                                        <TableCell>
                                            {formatDate(s.date)}{' '}
                                            <span className="text-muted-foreground">{s.time}</span>
                                        </TableCell>
                                        <TableCell>
                                            {s.customer ?? <span className="text-muted-foreground">Umum</span>}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {PAYMENT_LABEL[s.payment]}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">{s.itemCount}</TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-mono font-medium',
                                                s.status === 'VOID' && 'line-through',
                                            )}
                                        >
                                            {formatRupiah(s.paidTotal)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <StatusBadge status={s.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <TablePagination {...pagination} />
                </Panel>
            </div>

            {/* ----------------------------------------------------- detail nota */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <span className="font-mono text-sm">{detail?.number}</span>
                            {detail && <StatusBadge status={detail.status} />}
                        </DialogTitle>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-4">
                            <dl className="grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt className="label-eyebrow">Tanggal</dt>
                                    <dd>
                                        {formatDate(detail.date)} {detail.time}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="label-eyebrow">Kasir</dt>
                                    <dd>{detail.cashier}</dd>
                                </div>
                                <div>
                                    <dt className="label-eyebrow">Pembayaran</dt>
                                    <dd>{PAYMENT_LABEL[detail.payment]}</dd>
                                </div>
                            </dl>

                            <div className="overflow-hidden rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="label-eyebrow">Produk</TableHead>
                                            <TableHead className="label-eyebrow text-right">Qty</TableHead>
                                            <TableHead className="label-eyebrow text-right">Harga</TableHead>
                                            <TableHead className="label-eyebrow text-right">Jumlah</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {detail.items.map((it, i) => (
                                            <TableRow key={i}>
                                                <TableCell>{it.product}</TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatQty(it.qty)}{' '}
                                                    <span className="text-2xs text-muted-foreground">{it.uom}</span>
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatRupiah(it.price)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatRupiah(it.price * it.qty)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Ringkasan uang — pembulatan sengaja dipisah dari diskon */}
                            <dl className="space-y-1.5 rounded-md bg-muted/60 px-3 py-2.5 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Subtotal</dt>
                                    <dd className="font-mono tabular">{formatRupiah(detail.grossTotal)}</dd>
                                </div>
                                {detail.discountTotal > 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Diskon</dt>
                                        <dd className="font-mono tabular">-{formatRupiah(detail.discountTotal)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1.5">
                                    <dt className="text-muted-foreground">Nilai penjualan</dt>
                                    <dd className="font-mono tabular">{formatRupiah(detail.netTotal)}</dd>
                                </div>
                                {detail.roundingAmount !== 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            Pembulatan
                                            <span className="ml-1 text-2xs">(tidak mengurangi omzet)</span>
                                        </dt>
                                        <dd className="font-mono tabular">{formatRupiah(detail.roundingAmount)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1.5 text-base font-semibold">
                                    <dt>Diterima</dt>
                                    <dd className="font-mono tabular">{formatRupiah(detail.paidTotal)}</dd>
                                </div>
                                <div className="flex justify-between text-xs text-muted-foreground">
                                    <dt>HPP nota ini</dt>
                                    <dd className="font-mono tabular">{formatRupiah(detail.cogs)}</dd>
                                </div>
                            </dl>

                            <DialogFooter className="gap-2 sm:justify-between">
                                <Button variant="outline" size="sm">
                                    <Printer className="mr-1 h-3.5 w-3.5" /> Cetak Ulang
                                </Button>
                                {detail.status === 'POSTED' && (
                                    <Button variant="destructive" size="sm">
                                        <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Nota
                                    </Button>
                                )}
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
