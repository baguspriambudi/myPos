import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Download } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PageHeader, Panel, StatusBadge, formatDate, formatRupiah } from '@/components/pos-ui';
import { fiscalPeriods, paymentMix, topProducts } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Keuangan', href: '#' },
    { title: 'Laporan', href: '/laporan' },
];

export default function LaporanIndex() {
    const [periodId, setPeriodId] = useState(fiscalPeriods[0].id);
    const period = fiscalPeriods.find((p) => p.id === periodId)!;

    /** Susunan laba rugi sederhana. Indent menandai baris turunan. */
    const labaRugi = [
        { label: 'Penjualan bersih', value: period.netSales, strong: true },
        { label: 'Harga pokok penjualan', value: -period.cogs, indent: true },
        { label: 'Laba kotor', value: period.grossProfit, strong: true, divider: true },
        { label: 'Biaya operasional', value: -period.expenseTotal, indent: true },
        { label: 'Laba bersih', value: period.netProfit, strong: true, divider: true, highlight: true },
    ];

    const marginKotor = (period.grossProfit / period.netSales) * 100;
    const marginBersih = (period.netProfit / period.netSales) * 100;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laporan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Laporan Periode"
                    subtitle="Ringkasan hasil satu periode. Untuk periode yang sudah ditutup, angkanya diambil dari snapshot dan tidak berubah lagi."
                    action={
                        <Button size="sm" variant="outline">
                            <Download className="mr-1 h-3.5 w-3.5" /> Ekspor
                        </Button>
                    }
                />

                {/* -------------------------------------------- pemilih periode */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className="label-eyebrow">Periode</span>
                    <div className="flex flex-wrap gap-1">
                        {fiscalPeriods.map((p) => (
                            <button
                                key={p.id}
                                onClick={() => setPeriodId(p.id)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-md border px-2.5 py-1 font-mono text-xs transition-colors',
                                    periodId === p.id
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card hover:bg-accent',
                                )}
                            >
                                {p.code}
                            </button>
                        ))}
                    </div>
                    <StatusBadge status={period.status} />
                    <span className="text-xs text-muted-foreground">
                        {formatDate(period.startDate)} – {formatDate(period.endDate)}
                    </span>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    {/* ------------------------------------------------ laba rugi */}
                    <Panel
                        title="Laba Rugi"
                        description={period.status === 'CLOSED' ? `Snapshot · ditutup ${period.closedAt}` : 'Berjalan · angka belum final'}
                        className="lg:col-span-2"
                    >
                        <dl className="space-y-0.5">
                            {labaRugi.map((row) => (
                                <div
                                    key={row.label}
                                    className={cn(
                                        'flex items-baseline justify-between gap-4 py-1.5',
                                        row.divider && 'border-t pt-2',
                                        row.highlight && 'rounded-md bg-primary/5 px-2',
                                    )}
                                >
                                    <dt
                                        className={cn(
                                            'text-sm',
                                            row.indent && 'pl-4 text-muted-foreground',
                                            row.strong && 'font-medium',
                                        )}
                                    >
                                        {row.label}
                                    </dt>
                                    <dd
                                        className={cn(
                                            'font-mono tabular',
                                            row.strong ? 'text-lg font-semibold' : 'text-sm text-muted-foreground',
                                            row.highlight && 'text-primary',
                                        )}
                                    >
                                        {formatRupiah(row.value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        <div className="mt-4 grid grid-cols-3 gap-3 border-t pt-4">
                            <div>
                                <p className="label-eyebrow">Margin kotor</p>
                                <p className="mt-0.5 font-mono text-lg font-semibold tabular">
                                    {marginKotor.toFixed(1).replace('.', ',')}%
                                </p>
                            </div>
                            <div>
                                <p className="label-eyebrow">Margin bersih</p>
                                <p className="mt-0.5 font-mono text-lg font-semibold tabular">
                                    {marginBersih.toFixed(1).replace('.', ',')}%
                                </p>
                            </div>
                            <div>
                                <p className="label-eyebrow">Jumlah transaksi</p>
                                <p className="mt-0.5 font-mono text-lg font-semibold tabular">
                                    {period.transactions.toLocaleString('id-ID')}
                                </p>
                            </div>
                        </div>
                    </Panel>

                    {/* ------------------------------------------ metode pembayaran */}
                    <Panel title="Penerimaan" description="Menurut metode pembayaran" bodyClassName="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Metode</TableHead>
                                    <TableHead className="label-eyebrow text-right">Jumlah</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {paymentMix.map((p) => (
                                    <TableRow key={p.type}>
                                        <TableCell>{p.label}</TableCell>
                                        <TableCell className="text-right font-mono">
                                            {formatRupiah(p.amount)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="hover:bg-transparent">
                                    <TableCell className="font-medium">Total</TableCell>
                                    <TableCell className="text-right font-mono font-semibold">
                                        {formatRupiah(paymentMix.reduce((s, p) => s + p.amount, 0))}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </Panel>
                </div>

                {/* ------------------------------------------------ produk terlaris */}
                <Panel title="Produk Terlaris" description="Berdasarkan nilai penjualan" bodyClassName="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="label-eyebrow">Produk</TableHead>
                                <TableHead className="label-eyebrow text-right">Qty Terjual</TableHead>
                                <TableHead className="label-eyebrow text-right">Nilai Penjualan</TableHead>
                                <TableHead className="label-eyebrow text-right">Kontribusi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {topProducts.map((p) => {
                                const total = topProducts.reduce((s, x) => s + x.omzet, 0);
                                const share = (p.omzet / total) * 100;
                                return (
                                    <TableRow key={p.name}>
                                        <TableCell className="font-medium">{p.name}</TableCell>
                                        <TableCell className="text-right font-mono">
                                            {p.qty.toLocaleString('id-ID')}{' '}
                                            <span className="text-2xs text-muted-foreground">{p.uom}</span>
                                        </TableCell>
                                        <TableCell className="text-right font-mono">{formatRupiah(p.omzet)}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-primary"
                                                        style={{ width: `${share}%` }}
                                                    />
                                                </div>
                                                <span className="w-9 text-right font-mono text-2xs text-muted-foreground tabular">
                                                    {share.toFixed(0)}%
                                                </span>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </AppLayout>
    );
}
