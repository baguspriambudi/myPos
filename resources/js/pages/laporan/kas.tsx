import { Chip } from '@/components/master-ui';
import { KartuAngka, PemilihPeriode, TanpaPeriode, type PilihanPeriode } from '@/components/laporan-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatRupiah } from '@/components/pos-ui';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Buku Kas', href: '/laporan/kas' },
];

const LABEL_JENIS: Record<string, string> = {
    OPENING_CASH: 'Kas Awal Shift',
    CASH_SALE: 'Penjualan Tunai',
    CASH_IN: 'Kas Masuk',
    CASH_OUT: 'Kas Keluar',
    REFUND: 'Refund',
};

const LABEL_REFERENSI: Record<string, string> = {
    SALES: 'Nota',
    EXPENSE: 'Biaya',
    PAYMENT: 'Pembayaran Utang',
    RECEIVABLE: 'Pelunasan Piutang',
    CASH_DIFFERENCE: 'Selisih Kas',
};

interface Baris {
    id: number;
    date: string;
    type: string;
    amount: number;
    reference: string | null;
    note: string | null;
    branch: string | null;
    movesBalance: boolean;
    balance: number | null;
}

export default function BukuKas() {
    const { periods, period, summary, rows } = usePage().props as unknown as {
        periods: PilihanPeriode[];
        period: { id: number; code: string; status: string; startDate: string; endDate: string; branch: string | null } | null;
        summary: { opening: number; sales: number; in: number; out: number; refund: number; ending: number; difference: number } | null;
        rows: Baris[];
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Buku Kas" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Buku Kas"
                    subtitle="Seluruh pergerakan uang tunai pada satu periode, urut tanggal, dengan saldo berjalan."
                />

                <PemilihPeriode routeUrl="/laporan/kas" periods={periods} active={period} />

                {summary === null && <TanpaPeriode />}

                {summary !== null && (
                    <>
                        <KartuAngka
                            items={[
                                { label: 'Kas awal', value: formatRupiah(summary.opening) },
                                { label: 'Penjualan tunai', value: formatRupiah(summary.sales) },
                                { label: 'Kas keluar', value: formatRupiah(summary.out + summary.refund) },
                                { label: 'Kas akhir', value: formatRupiah(summary.ending), warn: summary.ending < 0 },
                            ]}
                        />

                        {summary.difference !== 0 && (
                            <p className="bg-warning/10 rounded-md px-3 py-2 text-xs">
                                Selisih kas tercatat pada periode ini {formatRupiah(summary.difference)}. Selisih yang belum diselesaikan
                                muncul sebagai peringatan saat menutup periode.
                            </p>
                        )}

                        <Panel
                            title="Pergerakan Kas"
                            description={`${rows.length} baris · baris "Kas Awal Shift" tidak menggerakkan saldo karena uangnya digulirkan dari shift sebelumnya`}
                            bodyClassName="p-0"
                        >
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-24">Tanggal</TableHead>
                                            <TableHead className="w-36">Jenis</TableHead>
                                            <TableHead className="w-36">Referensi</TableHead>
                                            <TableHead>Keterangan</TableHead>
                                            <TableHead className="w-32 text-right">Nilai</TableHead>
                                            <TableHead className="w-32 text-right">Saldo</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.length === 0 && (
                                            <EmptyRow colSpan={6} icon={<Wallet />} message="Belum ada pergerakan kas pada periode ini." />
                                        )}

                                        {rows.map((r) => (
                                            <TableRow key={r.id} className={cn(!r.movesBalance && 'text-muted-foreground')}>
                                                <TableCell className="tabular text-xs whitespace-nowrap">{formatDate(r.date)}</TableCell>
                                                <TableCell>
                                                    <Chip tone={r.amount >= 0 ? 'accent' : 'neutral'}>{LABEL_JENIS[r.type] ?? r.type}</Chip>
                                                </TableCell>
                                                <TableCell className="text-2xs text-muted-foreground">
                                                    {r.reference ? (LABEL_REFERENSI[r.reference] ?? r.reference) : '—'}
                                                </TableCell>
                                                <TableCell className="text-2xs">{r.note ?? '—'}</TableCell>
                                                <TableCell
                                                    className={cn(
                                                        'tabular text-right font-mono text-xs font-medium',
                                                        r.amount > 0 ? 'text-success' : 'text-destructive',
                                                    )}
                                                >
                                                    {formatRupiah(r.amount)}
                                                </TableCell>
                                                <TableCell className="tabular text-right font-mono text-xs">
                                                    {r.balance === null ? '—' : formatRupiah(r.balance)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Panel>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
