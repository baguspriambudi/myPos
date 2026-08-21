import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Boxes } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    EmptyRow,
    PageHeader,
    Panel,
    SearchInput,
    TablePagination,
    formatDate,
    formatQty,
    formatRupiah,
    usePagination,
} from '@/components/pos-ui';
import { products, stockMovements } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Kartu Stok', href: '/stok' },
];

const MOVEMENT_LABEL: Record<string, string> = {
    OPENING: 'Saldo Awal',
    PURCHASE: 'Pembelian',
    SALES: 'Penjualan',
    ADJUSTMENT_IN: 'Penyesuaian Masuk',
    ADJUSTMENT_OUT: 'Penyesuaian Keluar',
    TRANSFER_IN: 'Transfer Masuk',
    TRANSFER_OUT: 'Transfer Keluar',
    RETURN_IN: 'Retur Masuk',
    VOID_REVERSAL: 'Pembalikan Void',
};

export default function StokIndex() {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return stockMovements;
        return stockMovements.filter(
            (m) => m.product.toLowerCase().includes(q) || m.reference.toLowerCase().includes(q),
        );
    }, [search]);

    const pagination = usePagination(visible, 10);

    const nilaiPersediaan = products.reduce((s, p) => s + p.stock * p.avgCost, 0);
    const totalUnit = products.reduce((s, p) => s + p.stock, 0);
    const habis = products.filter((p) => p.stock <= 0).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kartu Stok" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Kartu Stok"
                    subtitle="Riwayat pergerakan stok dalam satuan dasar. Urutannya menjadi dasar perhitungan ulang HPP."
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Nilai persediaan</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatRupiah(nilaiPersediaan)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Total unit dasar</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatQty(totalUnit)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Produk habis</p>
                        <p
                            className={cn(
                                'mt-1 font-mono text-xl font-semibold tabular',
                                habis > 0 && 'text-destructive',
                            )}
                        >
                            {habis}
                        </p>
                    </div>
                </div>

                <SearchInput
                    value={search}
                    onChange={setSearch}
                    placeholder="Cari produk atau nomor referensi..."
                    className="w-full sm:max-w-xs"
                />

                <Panel
                    title="Pergerakan Stok"
                    description={`${visible.length} baris pada periode berjalan`}
                    bodyClassName="p-0"
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Tanggal</TableHead>
                                    <TableHead className="label-eyebrow">Jenis</TableHead>
                                    <TableHead className="label-eyebrow">Referensi</TableHead>
                                    <TableHead className="label-eyebrow">Produk</TableHead>
                                    <TableHead className="label-eyebrow text-right">Qty</TableHead>
                                    <TableHead className="label-eyebrow text-right">HPP Unit</TableHead>
                                    <TableHead className="label-eyebrow text-right">Saldo</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<Boxes />} message="Tidak ada pergerakan yang cocok." />
                                )}
                                {pagination.paged.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell className="whitespace-nowrap">{formatDate(m.date)}</TableCell>
                                        <TableCell>
                                            <span className="rounded bg-muted px-1.5 py-0.5 text-2xs">
                                                {MOVEMENT_LABEL[m.type] ?? m.type}
                                            </span>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {m.reference}
                                        </TableCell>
                                        <TableCell>{m.product}</TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-mono font-medium',
                                                m.qty > 0 ? 'text-success' : 'text-destructive',
                                            )}
                                        >
                                            {m.qty > 0 ? '+' : ''}
                                            {formatQty(m.qty)}{' '}
                                            <span className="text-2xs text-muted-foreground">{m.uom}</span>
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-muted-foreground">
                                            {formatRupiah(m.unitCost)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">{formatQty(m.balance)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <TablePagination {...pagination} />
                </Panel>
            </div>
        </AppLayout>
    );
}
