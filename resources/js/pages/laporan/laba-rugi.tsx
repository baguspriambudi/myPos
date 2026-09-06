import { Chip } from '@/components/master-ui';
import { KartuAngka, PemilihPeriode, TanpaPeriode, type PilihanPeriode } from '@/components/laporan-ui';
import { CompositionBar, PageHeader, Panel, formatDate, formatRupiah } from '@/components/pos-ui';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Laba Rugi', href: '/laporan/laba-rugi' },
];

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

interface Angka {
    source: 'snapshot' | 'berjalan';
    version: number | null;
    closedAt: string | null;
    saleCount: number;
    grossSales: number;
    discountTotal: number;
    salesReturnTotal: number;
    netSales: number;
    taxTotal: number;
    paymentRoundingTotal: number;
    cogs: number;
    grossProfit: number;
    expenseTotal: number;
    netProfit: number;
    payments: Record<string, number>;
    inventory: {
        opening: number;
        purchase: number;
        purchaseReturn: number;
        salesCost: number;
        salesReturnCost: number;
        adjustment: number;
        ending: number;
    };
    cash: { opening: number; sales: number; in: number; out: number; refund: number; ending: number; difference: number };
}

export default function LabaRugi() {
    const { periods, period, figures, expenses } = usePage().props as unknown as {
        periods: PilihanPeriode[];
        period: { id: number; code: string; status: string; startDate: string; endDate: string; branch: string | null } | null;
        figures: Angka | null;
        expenses: { name: string; count: number; amount: number }[];
    };

    const persen = (bagian: number, dasar: number) => (dasar === 0 ? '—' : `${((bagian / dasar) * 100).toFixed(1).replace('.', ',')}%`);

    const barisLabaRugi = figures
        ? [
              { label: 'Penjualan kotor', value: figures.grossSales },
              { label: 'Diskon', value: -figures.discountTotal, indent: true },
              { label: 'Retur penjualan', value: -figures.salesReturnTotal, indent: true },
              { label: 'Penjualan bersih', value: figures.netSales, strong: true, divider: true },
              { label: 'Harga pokok penjualan', value: -figures.cogs, indent: true },
              { label: 'Laba kotor', value: figures.grossProfit, strong: true, divider: true },
              { label: 'Biaya operasional', value: -figures.expenseTotal, indent: true },
              { label: 'Laba bersih', value: figures.netProfit, strong: true, divider: true, highlight: true },
          ]
        : [];

    const bauran = figures
        ? Object.entries(figures.payments)
              .filter(([, amount]) => amount > 0)
              .map(([type, amount]) => ({ label: LABEL_METODE[type] ?? type, amount }))
        : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laba Rugi" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Laba Rugi"
                    subtitle="Ringkasan hasil satu periode. Untuk periode yang sudah ditutup, angkanya diambil dari snapshot dan tidak berubah lagi."
                />

                <PemilihPeriode routeUrl="/laporan/laba-rugi" periods={periods} active={period} />

                {figures === null && <TanpaPeriode />}

                {figures !== null && (
                    <>
                        {figures.source === 'snapshot' ? (
                            <p className="text-muted-foreground flex items-center gap-2 text-xs">
                                <Lock className="h-3.5 w-3.5 shrink-0" />
                                Angka beku dari snapshot versi {figures.version}
                                {figures.closedAt && <> · ditutup {formatDate(figures.closedAt)}</>}. Transaksi baru tidak lagi mengubahnya.
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">
                                Periode masih berjalan — angka ini dihitung ulang tiap kali halaman dibuka dan belum final.
                            </p>
                        )}

                        <KartuAngka
                            items={[
                                { label: 'Penjualan bersih', value: formatRupiah(figures.netSales) },
                                { label: 'Laba kotor', value: formatRupiah(figures.grossProfit) },
                                { label: 'Biaya operasional', value: formatRupiah(figures.expenseTotal) },
                                { label: 'Laba bersih', value: formatRupiah(figures.netProfit), warn: figures.netProfit < 0 },
                            ]}
                        />

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Panel title="Laba Rugi" description={`${figures.saleCount} nota`} className="lg:col-span-2">
                                <dl className="space-y-0.5">
                                    {barisLabaRugi.map((row) => (
                                        <div
                                            key={row.label}
                                            className={cn(
                                                'flex items-baseline justify-between gap-4 py-1.5',
                                                row.divider && 'border-t pt-2',
                                                row.highlight && 'bg-primary/5 rounded-md px-2',
                                            )}
                                        >
                                            <dt className={cn('text-sm', row.indent && 'text-muted-foreground pl-4', row.strong && 'font-medium')}>
                                                {row.label}
                                            </dt>
                                            <dd
                                                className={cn(
                                                    'tabular font-mono',
                                                    row.strong ? 'text-lg font-semibold' : 'text-muted-foreground text-sm',
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
                                        <p className="tabular mt-0.5 font-mono text-lg font-semibold">
                                            {persen(figures.grossProfit, figures.netSales)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="label-eyebrow">Margin bersih</p>
                                        <p className="tabular mt-0.5 font-mono text-lg font-semibold">
                                            {persen(figures.netProfit, figures.netSales)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="label-eyebrow">Pajak</p>
                                        <p className="tabular mt-0.5 font-mono text-lg font-semibold">{formatRupiah(figures.taxTotal)}</p>
                                    </div>
                                </div>
                            </Panel>

                            <Panel title="Penerimaan" description="Menurut metode pembayaran">
                                {bauran.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Belum ada pembayaran pada periode ini.</p>
                                ) : (
                                    <CompositionBar items={bauran} />
                                )}

                                {figures.paymentRoundingTotal !== 0 && (
                                    <p className="text-2xs text-muted-foreground mt-3 border-t pt-3">
                                        Pembulatan pembayaran {formatRupiah(figures.paymentRoundingTotal)} — bukan diskon, dan tidak
                                        mengubah nilai penjualan.
                                    </p>
                                )}
                            </Panel>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Panel title="Persediaan" className="lg:col-span-2">
                                <dl className="space-y-1 text-xs">
                                    {[
                                        ['Persediaan awal', figures.inventory.opening],
                                        ['Pembelian', figures.inventory.purchase],
                                        ['Retur pembelian', -figures.inventory.purchaseReturn],
                                        ['HPP penjualan', -figures.inventory.salesCost],
                                        ['HPP retur penjualan', figures.inventory.salesReturnCost],
                                        ['Penyesuaian', figures.inventory.adjustment],
                                    ].map(([label, value]) => (
                                        <div key={label as string} className="flex justify-between">
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                        </div>
                                    ))}
                                    <div className="flex justify-between border-t pt-1 font-medium">
                                        <dt>Persediaan akhir</dt>
                                        <dd className="tabular font-mono">{formatRupiah(figures.inventory.ending)}</dd>
                                    </div>
                                </dl>
                            </Panel>

                            <Panel title="Biaya per Kategori" description={`${expenses.length} kategori terpakai`}>
                                {expenses.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Belum ada biaya operasional tercatat.</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {expenses.map((e) => (
                                            <li key={e.name} className="flex items-center gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm">{e.name}</p>
                                                    <p className="text-2xs text-muted-foreground">{e.count} transaksi</p>
                                                </div>
                                                <span className="tabular font-mono text-sm">{formatRupiah(e.amount)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        </div>

                        <Panel
                            title="Kas"
                            description="Saldo awal tidak menghitung ulang uang laci yang bergulir antar-shift"
                            action={figures.cash.difference !== 0 ? <Chip tone="warning">Ada selisih kas</Chip> : undefined}
                        >
                            <dl className="grid gap-x-8 gap-y-1 text-xs sm:grid-cols-2">
                                {[
                                    ['Kas awal', figures.cash.opening],
                                    ['Penjualan tunai', figures.cash.sales],
                                    ['Kas masuk lain', figures.cash.in],
                                    ['Kas keluar', -figures.cash.out],
                                    ['Refund', -figures.cash.refund],
                                    ['Selisih kas tercatat', figures.cash.difference],
                                ].map(([label, value]) => (
                                    <div key={label as string} className="flex justify-between">
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                    </div>
                                ))}
                                <div className="flex justify-between border-t pt-1 font-medium sm:col-span-2">
                                    <dt>Kas akhir</dt>
                                    <dd className="tabular font-mono">{formatRupiah(figures.cash.ending)}</dd>
                                </div>
                            </dl>
                        </Panel>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
