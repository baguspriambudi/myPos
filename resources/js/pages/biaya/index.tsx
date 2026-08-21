import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus, TriangleAlert, Wallet } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    CompositionBar,
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
import { cashSummary, expenseByCategory, expenses } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Keuangan', href: '#' },
    { title: 'Kas & Biaya', href: '/biaya' },
];

const PAYMENT_LABEL: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

export default function BiayaIndex() {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return expenses;
        return expenses.filter(
            (e) => e.description.toLowerCase().includes(q) || e.category.toLowerCase().includes(q),
        );
    }, [search]);

    const pagination = usePagination(visible, 10);
    const totalBiaya = expenses.filter((e) => e.status === 'POSTED').reduce((s, e) => s + e.amount, 0);

    const arusKas = [
        { label: 'Saldo awal', value: cashSummary.opening, tone: '' },
        { label: 'Kas masuk', value: cashSummary.cashIn, tone: 'text-success' },
        { label: 'Kas keluar', value: -cashSummary.cashOut, tone: 'text-destructive' },
        { label: 'Saldo akhir', value: cashSummary.closing, tone: 'font-semibold' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kas & Biaya" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Kas & Biaya"
                    subtitle="Biaya operasional mengurangi laba bersih periode, tetapi tidak pernah menyentuh harga pokok persediaan."
                    action={
                        <Button size="sm">
                            <Plus className="mr-1 h-3.5 w-3.5" /> Catat Biaya
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Posisi Kas" description="Periode berjalan" className="lg:col-span-2">
                        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            {arusKas.map((k) => (
                                <div key={k.label}>
                                    <dt className="label-eyebrow">{k.label}</dt>
                                    <dd className={cn('mt-0.5 font-mono text-lg tabular', k.tone)}>
                                        {formatRupiah(k.value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {cashSummary.difference !== 0 && (
                            <p className="mt-4 flex items-start gap-2 rounded-md bg-warning/10 px-3 py-2 text-xs">
                                <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                                <span>
                                    Selisih kas {formatRupiah(cashSummary.difference)} belum diselesaikan. Selisih tidak
                                    dapat dihapus — hanya diselesaikan lalu ditandai selesai.
                                </span>
                            </p>
                        )}
                    </Panel>

                    <Panel title="Biaya per Kategori" description={formatRupiah(totalBiaya)}>
                        <CompositionBar
                            items={expenseByCategory.map((e) => ({ label: e.category, amount: e.amount }))}
                        />
                    </Panel>
                </div>

                <SearchInput
                    value={search}
                    onChange={setSearch}
                    placeholder="Cari keterangan atau kategori..."
                    className="w-full sm:max-w-xs"
                />

                <Panel title="Daftar Biaya" description={`${visible.length} catatan`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Nomor</TableHead>
                                    <TableHead className="label-eyebrow">Tanggal</TableHead>
                                    <TableHead className="label-eyebrow">Kategori</TableHead>
                                    <TableHead className="label-eyebrow">Keterangan</TableHead>
                                    <TableHead className="label-eyebrow">Bayar</TableHead>
                                    <TableHead className="label-eyebrow text-right">Jumlah</TableHead>
                                    <TableHead className="label-eyebrow text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<Wallet />} message="Tidak ada biaya yang cocok." />
                                )}
                                {pagination.paged.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell className="font-mono text-xs">{e.number}</TableCell>
                                        <TableCell className="whitespace-nowrap">{formatDate(e.date)}</TableCell>
                                        <TableCell>
                                            <span className="rounded bg-muted px-1.5 py-0.5 text-2xs">{e.category}</span>
                                        </TableCell>
                                        <TableCell>{e.description}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {PAYMENT_LABEL[e.payment]}
                                        </TableCell>
                                        <TableCell className="text-right font-mono font-medium">
                                            {formatRupiah(e.amount)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <StatusBadge status={e.status} />
                                        </TableCell>
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
