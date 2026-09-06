import { KartuAngka, PemilihPeriode, TanpaPeriode, type PilihanPeriode } from '@/components/laporan-ui';
import { BarChart, EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { LineChart, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Penjualan', href: '#' },
    { title: 'Laporan Penjualan', href: '/laporan/penjualan' },
];

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

interface Ringkasan {
    saleCount: number;
    grossSales: number;
    discountTotal: number;
    taxTotal: number;
    salesReturnTotal: number;
    netSales: number;
    paymentRoundingTotal: number;
    cogs: number;
    grossProfit: number;
    payments: Record<string, number>;
}

export default function LaporanPenjualan() {
    const { periods, period, summary, daily, products, cashiers } = usePage().props as unknown as {
        periods: PilihanPeriode[];
        period: { id: number; code: string; status: string; startDate: string; endDate: string; branch: string | null } | null;
        summary: Ringkasan | null;
        daily: { date: string; saleCount: number; netSales: number; cogs: number; grossProfit: number }[];
        products: { name: string; sku: string; uom: string; qty: number; netSales: number; cogs: number; grossProfit: number }[];
        cashiers: { name: string; saleCount: number; netSales: number }[];
    };

    const omzetProduk = products.reduce((s, p) => s + p.netSales, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laporan Penjualan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Laporan Penjualan"
                    subtitle="Membedah penjualan satu periode: per hari, per produk, dan per kasir. Hanya nota yang sah ikut terhitung."
                />

                <PemilihPeriode routeUrl="/laporan/penjualan" periods={periods} active={period} />

                {summary === null && <TanpaPeriode />}

                {summary !== null && (
                    <>
                        <KartuAngka
                            items={[
                                { label: 'Jumlah nota', value: String(summary.saleCount) },
                                { label: 'Penjualan bersih', value: formatRupiah(summary.netSales) },
                                { label: 'HPP', value: formatRupiah(summary.cogs) },
                                { label: 'Laba kotor', value: formatRupiah(summary.grossProfit), warn: summary.grossProfit < 0 },
                            ]}
                        />

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Panel
                                title="Penjualan Harian"
                                description={`${daily.length} hari bertransaksi`}
                                className="lg:col-span-2"
                                action={
                                    <div className="text-muted-foreground flex items-center gap-3 text-xs">
                                        <span className="flex items-center gap-1.5">
                                            <span className="bg-primary/85 h-2 w-2 rounded-sm" /> Omzet
                                        </span>
                                        <span className="flex items-center gap-1.5">
                                            <span className="bg-primary/25 h-2 w-2 rounded-sm" /> HPP
                                        </span>
                                    </div>
                                }
                            >
                                {daily.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Belum ada penjualan pada periode ini.</p>
                                ) : (
                                    <BarChart data={daily.map((d) => ({ date: d.date.slice(8), omzet: d.netSales, hpp: d.cogs }))} />
                                )}
                            </Panel>

                            <Panel title="Rekap Nilai">
                                <dl className="space-y-1 text-xs">
                                    {[
                                        ['Penjualan kotor', summary.grossSales],
                                        ['Diskon', -summary.discountTotal],
                                        ['Retur penjualan', -summary.salesReturnTotal],
                                        ['Pajak', summary.taxTotal],
                                        ['Pembulatan pembayaran', summary.paymentRoundingTotal],
                                    ].map(([label, value]) => (
                                        <div key={label as string} className="flex justify-between">
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                        </div>
                                    ))}
                                    <div className="flex justify-between border-t pt-1 font-medium">
                                        <dt>Penjualan bersih</dt>
                                        <dd className="tabular font-mono">{formatRupiah(summary.netSales)}</dd>
                                    </div>
                                </dl>

                                <div className="mt-3 space-y-1 border-t pt-3 text-xs">
                                    <p className="label-eyebrow">Metode pembayaran</p>
                                    {Object.entries(summary.payments)
                                        .filter(([, amount]) => amount > 0)
                                        .map(([type, amount]) => (
                                            <div key={type} className="flex justify-between">
                                                <dt className="text-muted-foreground">{LABEL_METODE[type] ?? type}</dt>
                                                <dd className="tabular font-mono">{formatRupiah(amount)}</dd>
                                            </div>
                                        ))}
                                </div>
                            </Panel>
                        </div>

                        <Panel title="Produk Terlaris" description="Berdasarkan nilai penjualan, qty dalam satuan dasar" bodyClassName="p-0">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Produk</TableHead>
                                            <TableHead className="w-28 text-right">Qty</TableHead>
                                            <TableHead className="w-32 text-right">Penjualan</TableHead>
                                            <TableHead className="w-32 text-right">HPP</TableHead>
                                            <TableHead className="w-32 text-right">Laba Kotor</TableHead>
                                            <TableHead className="w-28 text-right">Kontribusi</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {products.length === 0 && (
                                            <EmptyRow colSpan={6} icon={<LineChart />} message="Belum ada produk terjual pada periode ini." />
                                        )}

                                        {products.map((p) => {
                                            const share = omzetProduk === 0 ? 0 : (p.netSales / omzetProduk) * 100;
                                            return (
                                                <TableRow key={p.sku}>
                                                    <TableCell>
                                                        <p className="text-xs font-medium">{p.name}</p>
                                                        <p className="text-2xs text-muted-foreground font-mono">{p.sku}</p>
                                                    </TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">
                                                        {formatQty(p.qty)}{' '}
                                                        <span className="text-2xs text-muted-foreground">{p.uom}</span>
                                                    </TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">
                                                        {formatRupiah(p.netSales)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground tabular text-right font-mono text-xs">
                                                        {formatRupiah(p.cogs)}
                                                    </TableCell>
                                                    <TableCell
                                                        className={cn(
                                                            'tabular text-right font-mono text-xs font-medium',
                                                            p.grossProfit < 0 && 'text-destructive',
                                                        )}
                                                    >
                                                        {formatRupiah(p.grossProfit)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <div className="bg-muted h-1.5 w-16 overflow-hidden rounded-full">
                                                                <div className="bg-primary h-full rounded-full" style={{ width: `${share}%` }} />
                                                            </div>
                                                            <span className="text-muted-foreground tabular w-9 text-right font-mono text-2xs">
                                                                {share.toFixed(0)}%
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </Panel>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <Panel title="Per Kasir" description="Menurut pengguna yang menerbitkan nota" bodyClassName="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Kasir</TableHead>
                                            <TableHead className="w-24 text-right">Nota</TableHead>
                                            <TableHead className="w-36 text-right">Penjualan</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cashiers.length === 0 && <EmptyRow colSpan={3} icon={<Users />} message="Belum ada nota." />}
                                        {cashiers.map((c) => (
                                            <TableRow key={c.name}>
                                                <TableCell className="text-xs">{c.name}</TableCell>
                                                <TableCell className="tabular text-right font-mono text-xs">{c.saleCount}</TableCell>
                                                <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(c.netSales)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Panel>

                            <Panel title="Rincian Harian" bodyClassName="p-0">
                                <div className="max-h-80 overflow-y-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-28">Tanggal</TableHead>
                                                <TableHead className="w-20 text-right">Nota</TableHead>
                                                <TableHead className="text-right">Penjualan</TableHead>
                                                <TableHead className="text-right">Laba Kotor</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {daily.length === 0 && <EmptyRow colSpan={4} icon={<LineChart />} message="Belum ada penjualan." />}
                                            {daily.map((d) => (
                                                <TableRow key={d.date}>
                                                    <TableCell className="tabular text-xs">{formatDate(d.date)}</TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">{d.saleCount}</TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">
                                                        {formatRupiah(d.netSales)}
                                                    </TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">
                                                        {formatRupiah(d.grossProfit)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </Panel>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
