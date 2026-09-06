import { Chip, NativeSelect } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Kartu Stok', href: '/stok' },
];

const LABEL_JENIS: Record<string, string> = {
    OPENING: 'Saldo Awal',
    PURCHASE: 'Penerimaan',
    SALES: 'Penjualan',
    ADJUSTMENT_IN: 'Penyesuaian Masuk',
    ADJUSTMENT_OUT: 'Penyesuaian Keluar',
    TRANSFER_IN: 'Transfer Masuk',
    TRANSFER_OUT: 'Transfer Keluar',
    RETURN_IN: 'Retur Masuk',
    RETURN_OUT: 'Retur Keluar',
    VOID_REVERSAL: 'Pembalikan Void',
    ROUNDING: 'Koreksi Pembulatan',
    CORRECTION: 'Koreksi',
};

const LABEL_REFERENSI: Record<string, string> = {
    SALES: 'Nota',
    RECEIPT: 'Penerimaan',
    PURCHASE: 'Pembelian',
    ADJUSTMENT: 'Penyesuaian',
    OPNAME: 'Opname',
    TRANSFER: 'Transfer',
    SALES_RETURN: 'Retur Jual',
    PURCHASE_RETURN: 'Retur Beli',
    OPENING: 'Saldo Awal',
    CLOSING: 'Penutupan',
};

interface Pergerakan {
    id: number;
    date: string;
    type: string;
    reference: string | null;
    referenceId: number | null;
    product: string;
    sku: string;
    qtyBase: number;
    unitCost: number | null;
    totalCost: number | null;
    balance: number | null;
}

export default function StokIndex() {
    const { products, productId, movements, summary, canSeeCost, branch } = usePage().props as unknown as {
        products: { id: number; name: string; sku: string }[];
        productId: number | null;
        movements: Pergerakan[];
        summary: { productCount: number; totalQty: number; emptyCount: number; totalValue: number | null } | null;
        canSeeCost: boolean;
        branch: { id: number; name: string } | null;
    };

    const kartuSatuProduk = productId !== null;

    function pilihProduk(nilai: string) {
        router.get('/stok', nilai === '' ? {} : { produk: Number(nilai) }, { preserveState: true, preserveScroll: true });
    }

    const kolom = 6 + (canSeeCost ? 1 : 0) + (kartuSatuProduk ? 1 : 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kartu Stok" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Kartu Stok"
                    subtitle={
                        kartuSatuProduk
                            ? 'Riwayat satu produk, urut maju dengan saldo berjalan — urutan inilah yang dipakai perhitungan ulang HPP.'
                            : 'Pergerakan stok di lokasi ini dalam satuan dasar. Pilih satu produk untuk melihat saldo berjalannya.'
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="bg-card rounded-lg border px-4 py-3">
                        <p className="label-eyebrow">{canSeeCost ? 'Nilai persediaan' : 'Produk bersaldo'}</p>
                        <p className="tabular mt-1 font-mono text-xl font-semibold">
                            {canSeeCost ? formatRupiah(summary?.totalValue ?? 0) : (summary?.productCount ?? 0)}
                        </p>
                    </div>
                    <div className="bg-card rounded-lg border px-4 py-3">
                        <p className="label-eyebrow">Total unit dasar</p>
                        <p className="tabular mt-1 font-mono text-xl font-semibold">{formatQty(summary?.totalQty ?? 0)}</p>
                    </div>
                    <div className="bg-card rounded-lg border px-4 py-3">
                        <p className="label-eyebrow">Produk habis</p>
                        <p className={cn('tabular mt-1 font-mono text-xl font-semibold', (summary?.emptyCount ?? 0) > 0 && 'text-destructive')}>
                            {summary?.emptyCount ?? 0}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="label-eyebrow">Produk</span>
                    <div className="w-full sm:max-w-sm">
                        <NativeSelect
                            value={productId ?? ''}
                            onChange={pilihProduk}
                            placeholder="Semua produk"
                            options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                        />
                    </div>
                    {branch && <span className="text-muted-foreground text-xs">di {branch.name}</span>}
                </div>

                <Panel
                    title="Pergerakan Stok"
                    description={`${movements.length} baris${kartuSatuProduk ? '' : ' terbaru'}`}
                    bodyClassName="p-0"
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead className="w-36">Jenis</TableHead>
                                    <TableHead className="w-28">Referensi</TableHead>
                                    {!kartuSatuProduk && <TableHead>Produk</TableHead>}
                                    <TableHead className="w-28 text-right">Qty</TableHead>
                                    {canSeeCost && <TableHead className="w-28 text-right">HPP Unit</TableHead>}
                                    {canSeeCost && <TableHead className="w-28 text-right">Nilai</TableHead>}
                                    {kartuSatuProduk && <TableHead className="w-28 text-right">Saldo</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.length === 0 && (
                                    <EmptyRow colSpan={kolom} icon={<Boxes />} message="Belum ada pergerakan stok di lokasi ini." />
                                )}

                                {movements.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell className="tabular text-xs whitespace-nowrap">{formatDate(m.date)}</TableCell>
                                        <TableCell>
                                            <Chip tone={m.qtyBase >= 0 ? 'accent' : 'neutral'}>{LABEL_JENIS[m.type] ?? m.type}</Chip>
                                        </TableCell>
                                        <TableCell className="text-2xs text-muted-foreground">
                                            {m.reference ? (LABEL_REFERENSI[m.reference] ?? m.reference) : '—'}
                                            {m.referenceId !== null && <span className="ml-1 font-mono">#{m.referenceId}</span>}
                                        </TableCell>
                                        {!kartuSatuProduk && (
                                            <TableCell className="text-xs">
                                                {m.product}
                                                <span className="text-2xs text-muted-foreground block font-mono">{m.sku}</span>
                                            </TableCell>
                                        )}
                                        <TableCell
                                            className={cn(
                                                'tabular text-right font-mono text-xs font-medium',
                                                m.qtyBase > 0 ? 'text-success' : m.qtyBase < 0 ? 'text-destructive' : 'text-muted-foreground',
                                            )}
                                        >
                                            {m.qtyBase > 0 ? '+' : ''}
                                            {formatQty(m.qtyBase)}
                                        </TableCell>
                                        {canSeeCost && (
                                            <TableCell className="text-muted-foreground tabular text-right font-mono text-xs">
                                                {formatRupiah(m.unitCost ?? 0)}
                                            </TableCell>
                                        )}
                                        {canSeeCost && (
                                            <TableCell className="tabular text-right font-mono text-xs">
                                                {formatRupiah(m.totalCost ?? 0)}
                                            </TableCell>
                                        )}
                                        {kartuSatuProduk && (
                                            <TableCell className="tabular text-right font-mono text-xs font-semibold">
                                                {formatQty(m.balance ?? 0)}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Panel>
            </div>
        </AppLayout>
    );
}
