import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus, Truck } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    EmptyRow,
    PageHeader,
    Panel,
    SearchInput,
    StatusBadge,
    TablePagination,
    formatDate,
    formatRupiah,
    usePagination,
} from '@/components/pos-ui';
import { purchases } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transaksi', href: '#' },
    { title: 'Pembelian', href: '/pembelian' },
];

export default function PembelianIndex() {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return purchases;
        return purchases.filter(
            (p) => p.number.toLowerCase().includes(q) || p.supplier.toLowerCase().includes(q),
        );
    }, [search]);

    const pagination = usePagination(visible, 10);

    const posted = purchases.filter((p) => p.status === 'POSTED');
    const totalBelanja = posted.reduce((s, p) => s + p.total, 0);
    const totalUtang = posted.reduce((s, p) => s + (p.total - p.paid), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pembelian" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Pembelian"
                    subtitle="Barang masuk dari pemasok. Setiap pembelian membentuk lot persediaan dengan harga pokoknya sendiri."
                    action={
                        <Button size="sm">
                            <Plus className="mr-1 h-3.5 w-3.5" /> Pembelian Baru
                        </Button>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Nota pembelian</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{purchases.length}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Total belanja</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatRupiah(totalBelanja)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Utang belum dibayar</p>
                        <p
                            className={cn(
                                'mt-1 font-mono text-xl font-semibold tabular',
                                totalUtang > 0 && 'text-warning',
                            )}
                        >
                            {formatRupiah(totalUtang)}
                        </p>
                    </div>
                </div>

                <SearchInput
                    value={search}
                    onChange={setSearch}
                    placeholder="Cari nomor atau pemasok..."
                    className="w-full sm:max-w-xs"
                />

                <Panel title="Daftar Pembelian" description={`${visible.length} nota`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Nomor</TableHead>
                                    <TableHead className="label-eyebrow">Tanggal</TableHead>
                                    <TableHead className="label-eyebrow">Pemasok</TableHead>
                                    <TableHead className="label-eyebrow text-right">Item</TableHead>
                                    <TableHead className="label-eyebrow text-right">Total</TableHead>
                                    <TableHead className="label-eyebrow text-right">Sisa Utang</TableHead>
                                    <TableHead className="label-eyebrow text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<Truck />} message="Tidak ada pembelian yang cocok." />
                                )}
                                {pagination.paged.map((p) => {
                                    const sisa = p.total - p.paid;
                                    return (
                                        <TableRow key={p.id}>
                                            <TableCell className="font-mono text-xs">{p.number}</TableCell>
                                            <TableCell>{formatDate(p.date)}</TableCell>
                                            <TableCell>{p.supplier}</TableCell>
                                            <TableCell className="text-right font-mono">{p.itemCount}</TableCell>
                                            <TableCell className="text-right font-mono font-medium">
                                                {formatRupiah(p.total)}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-right font-mono',
                                                    sisa > 0 ? 'text-warning' : 'text-muted-foreground',
                                                )}
                                            >
                                                {sisa > 0 ? formatRupiah(sisa) : 'Lunas'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <StatusBadge status={p.status} />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                    <TablePagination {...pagination} />
                </Panel>
            </div>
        </AppLayout>
    );
}
