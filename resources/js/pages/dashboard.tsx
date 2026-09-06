import { Chip } from '@/components/master-ui';
import { BarChart, CompositionBar, EmptyRow, Panel, StatCard, formatQty, formatRupiah, formatRupiahShort } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, Banknote, Package, PackageX, Receipt, ReceiptText, TrendingUp } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const LABEL_STATUS_PERIODE: Record<string, string> = {
    DRAFT: 'Draf',
    OPEN: 'Terbuka',
    CLOSING: 'Sedang Ditutup',
    CLOSED: 'Ditutup',
    REOPENED: 'Dibuka Ulang',
};

const LABEL_STATUS_NOTA: Record<string, string> = {
    DRAFT: 'Draf',
    POSTED: 'Sah',
    VOID: 'Dibatalkan',
};

interface Hari {
    saleCount: number;
    saleCountDelta: number | null;
    omzet: number | null;
    omzetDelta: number | null;
    cogs: number | null;
    cogsDelta: number | null;
    grossProfit: number | null;
    grossProfitDelta: number | null;
}

interface Nota {
    id: number;
    number: string;
    date: string;
    cashier: string | null;
    status: string;
    paidTotal: number | null;
}

interface Perhatian {
    openShifts: number;
    cashDifferences: number;
    uninvoicedReceipts: number;
    negativeStock: number;
    periodMissing: number;
}

export default function Dashboard() {
    const { branch, owner, period, today, trend, paymentMix, recentSales, topProducts, emptyStock, attention, canSeeMoney, todayLabel } =
        usePage().props as unknown as {
            branch: { id: number; name: string; type: string; canSell: boolean } | null;
            owner: { name: string | null; costingMethod: string | null; roundingUnit: number; roundingMode: string | null };
            period: { code: string; name: string; status: string; day: number; totalDays: number; menerimaTransaksi: boolean } | null;
            today: Hari | null;
            trend: { date: string; omzet: number; hpp: number }[];
            paymentMix: { label: string; amount: number }[];
            recentSales: Nota[];
            topProducts: { name: string; uom: string; qty: number; omzet: number }[];
            emptyStock: { name: string; uom: string; qty: number }[];
            attention: Perhatian | null;
            canSeeMoney: boolean;
            todayLabel: string;
        };

    const progress = period ? Math.round((period.day / period.totalDays) * 100) : 0;

    const perhatian = attention
        ? [
              { label: 'Shift kasir masih terbuka', value: attention.openShifts, href: '/kasir' },
              { label: 'Selisih kas belum diselesaikan', value: attention.cashDifferences, href: '/periode' },
              { label: 'Penerimaan belum difakturkan', value: attention.uninvoicedReceipts, href: '/pembelian' },
              { label: 'Produk bersaldo negatif', value: attention.negativeStock, href: '/laporan/persediaan' },
          ].filter((p) => p.value > 0)
        : [];

    const adaPembayaran = paymentMix.some((p) => p.amount > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                {/* ---------------------------------------------- kepala halaman */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">Ringkasan Hari Ini</h1>
                        <p className="text-muted-foreground text-sm">
                            {branch?.name ?? 'Belum terhubung ke lokasi mana pun'} · {todayLabel}
                        </p>
                    </div>
                    <Button size="sm" asChild>
                        <Link href={branch?.canSell ? '/kasir' : '/stok'}>
                            {branch?.canSell ? 'Buka kasir' : 'Lihat kartu stok'}
                            <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                        </Link>
                    </Button>
                </div>

                {/* ------------------------------------------- status periode aktif */}
                <div className="bg-card flex flex-wrap items-center gap-x-5 gap-y-3 rounded-lg border px-4 py-3">
                    {period === null ? (
                        <p className="text-destructive flex items-center gap-2 text-sm">
                            <AlertTriangle className="h-4 w-4 shrink-0" />
                            Hari ini belum berada dalam periode pembukuan mana pun — seluruh transaksi akan ditolak.
                            <Link href="/periode" className="underline underline-offset-2">
                                Buat periodenya
                            </Link>
                        </p>
                    ) : (
                        <>
                            <div className="flex items-center gap-2.5">
                                <span className="label-eyebrow">Periode aktif</span>
                                <span className="font-mono text-sm font-semibold">{period.code}</span>
                                <Chip tone={period.menerimaTransaksi ? 'accent' : 'warning'}>
                                    {LABEL_STATUS_PERIODE[period.status] ?? period.status}
                                </Chip>
                            </div>

                            <div className="flex min-w-45 flex-1 items-center gap-2.5">
                                <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                    <div className="bg-primary h-full rounded-full" style={{ width: `${progress}%` }} />
                                </div>
                                <span className="text-muted-foreground tabular font-mono text-xs">
                                    hari {period.day}/{period.totalDays}
                                </span>
                            </div>

                            <div className="text-muted-foreground flex items-center gap-4 text-xs">
                                <span>
                                    Costing <span className="text-foreground font-medium">{owner.costingMethod}</span>
                                </span>
                                <span>
                                    Pembulatan{' '}
                                    <span className="text-foreground font-medium">
                                        {owner.roundingUnit === 0 ? 'nonaktif' : `${owner.roundingUnit} · ${owner.roundingMode}`}
                                    </span>
                                </span>
                            </div>
                        </>
                    )}
                </div>

                {/* ------------------------------------------------ perlu perhatian */}
                {perhatian.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {perhatian.map((p) => (
                            <Link
                                key={p.label}
                                href={p.href}
                                className="bg-warning/10 hover:bg-warning/20 flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs transition-colors"
                            >
                                <AlertTriangle className="text-warning h-3.5 w-3.5 shrink-0" />
                                <span>{p.label}</span>
                                <span className="tabular font-mono font-semibold">{p.value}</span>
                            </Link>
                        ))}
                    </div>
                )}

                {/* ------------------------------------------------------ kartu KPI */}
                {today !== null && (
                    <div className={cn('grid gap-3 sm:grid-cols-2', canSeeMoney && 'xl:grid-cols-4')}>
                        <StatCard
                            label="Transaksi"
                            value={String(today.saleCount)}
                            delta={today.saleCountDelta ?? undefined}
                            caption="nota hari ini"
                            icon={<Receipt className="h-4 w-4" />}
                        />
                        {canSeeMoney && (
                            <>
                                <StatCard
                                    label="Omzet"
                                    value={formatRupiahShort(today.omzet ?? 0)}
                                    delta={today.omzetDelta ?? undefined}
                                    caption="dibanding kemarin"
                                    icon={<Banknote className="h-4 w-4" />}
                                />
                                <StatCard
                                    label="HPP"
                                    value={formatRupiahShort(today.cogs ?? 0)}
                                    delta={today.cogsDelta ?? undefined}
                                    caption="harga pokok terjual"
                                    icon={<Package className="h-4 w-4" />}
                                />
                                <StatCard
                                    label="Laba Kotor"
                                    value={formatRupiahShort(today.grossProfit ?? 0)}
                                    delta={today.grossProfitDelta ?? undefined}
                                    caption="omzet dikurangi HPP"
                                    icon={<TrendingUp className="h-4 w-4" />}
                                    tone="positive"
                                />
                            </>
                        )}
                    </div>
                )}

                {/* ------------------------------------------- grafik & metode bayar */}
                {canSeeMoney && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Panel
                            title="Omzet & HPP"
                            description="14 hari terakhir"
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
                            <BarChart data={trend.map((t) => ({ date: t.date.slice(8), omzet: t.omzet, hpp: t.hpp }))} />
                        </Panel>

                        <Panel title="Metode Pembayaran" description="Hari ini">
                            {adaPembayaran ? (
                                <CompositionBar items={paymentMix} />
                            ) : (
                                <p className="text-muted-foreground text-sm">Belum ada pembayaran hari ini.</p>
                            )}
                        </Panel>
                    </div>
                )}

                {/* --------------------------------------------- transaksi & produk */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel
                        title="Nota Terakhir"
                        className="lg:col-span-2"
                        bodyClassName="p-0"
                        action={
                            <Button variant="ghost" size="sm" className="h-7 text-xs" asChild>
                                <Link href="/penjualan">Semua</Link>
                            </Button>
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Kasir</TableHead>
                                    {canSeeMoney && <TableHead className="w-28 text-right">Total</TableHead>}
                                    <TableHead className="w-24 text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentSales.length === 0 && (
                                    <EmptyRow colSpan={canSeeMoney ? 5 : 4} icon={<ReceiptText />} message="Belum ada nota di lokasi ini." />
                                )}
                                {recentSales.map((sale) => (
                                    <TableRow key={sale.id}>
                                        <TableCell className="tabular font-mono text-xs">{sale.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{sale.date}</TableCell>
                                        <TableCell className="text-xs">{sale.cashier ?? '—'}</TableCell>
                                        {canSeeMoney && (
                                            <TableCell className="tabular text-right font-mono text-xs font-medium">
                                                {formatRupiah(sale.paidTotal ?? 0)}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right">
                                            <Chip tone={sale.status === 'POSTED' ? 'accent' : 'neutral'}>
                                                {LABEL_STATUS_NOTA[sale.status] ?? sale.status}
                                            </Chip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    <div className="flex flex-col gap-4">
                        {canSeeMoney && (
                            <Panel title="Produk Terlaris" description="Hari ini">
                                {topProducts.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Belum ada penjualan hari ini.</p>
                                ) : (
                                    <ul className="space-y-2.5">
                                        {topProducts.map((p, i) => (
                                            <li key={p.name} className="flex items-center gap-2.5">
                                                <span className="bg-muted text-2xs text-muted-foreground flex h-5 w-5 shrink-0 items-center justify-center rounded font-mono font-semibold">
                                                    {i + 1}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">{p.name}</p>
                                                    <p className="text-2xs text-muted-foreground font-mono">
                                                        {formatQty(p.qty)} {p.uom}
                                                    </p>
                                                </div>
                                                <span className="tabular font-mono text-sm">{formatRupiahShort(p.omzet)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        )}

                        <Panel
                            title="Stok Habis"
                            description={emptyStock.length > 0 ? `${emptyStock.length} produk bersaldo nol atau kurang` : 'Tidak ada'}
                            action={emptyStock.length > 0 ? <AlertTriangle className="text-warning h-4 w-4" /> : undefined}
                        >
                            {emptyStock.length === 0 ? (
                                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                    <PackageX className="h-4 w-4" /> Seluruh produk masih bersaldo.
                                </p>
                            ) : (
                                <ul className="space-y-2.5">
                                    {emptyStock.map((p) => (
                                        <li key={p.name} className="flex items-center gap-2.5">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{p.name}</p>
                                                <p className="text-2xs text-muted-foreground font-mono">{p.uom}</p>
                                            </div>
                                            <span
                                                className={cn(
                                                    'tabular font-mono text-sm font-semibold',
                                                    p.qty < 0 ? 'text-destructive' : 'text-warning',
                                                )}
                                            >
                                                {formatQty(p.qty)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
