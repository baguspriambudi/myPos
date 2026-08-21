import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    Banknote,
    Coins,
    Package,
    Receipt,
    TrendingUp,
} from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    BarChart,
    CompositionBar,
    Panel,
    StatCard,
    StatusBadge,
    formatQty,
    formatRupiah,
    formatRupiahShort,
} from '@/components/pos-ui';
import { activePeriod, branch, kpi, paymentMix, products, recentActivity, sales, salesTrend, topProducts } from '@/data/dummy';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

export default function Dashboard() {
    const lowStock = products.filter((p) => p.active && p.stock < p.minStock);
    const recentSales = sales.slice(0, 6);
    const progress = Math.round((activePeriod.day / activePeriod.totalDays) * 100);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                {/* ---------------------------------------------- kepala halaman */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">Ringkasan Hari Ini</h1>
                        <p className="text-sm text-muted-foreground">
                            {branch.name} · Rabu, 19 Agustus 2026
                        </p>
                    </div>
                    <Button size="sm" asChild>
                        <Link href="/penjualan">
                            Lihat penjualan
                            <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                        </Link>
                    </Button>
                </div>

                {/* ------------------------------------------- status periode aktif */}
                <div className="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-lg border bg-card px-4 py-3">
                    <div className="flex items-center gap-2.5">
                        <span className="label-eyebrow">Periode aktif</span>
                        <span className="font-mono text-sm font-semibold">{activePeriod.code}</span>
                        <StatusBadge status={activePeriod.status} />
                    </div>

                    <div className="flex min-w-45 flex-1 items-center gap-2.5">
                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                            <div className="h-full rounded-full bg-primary" style={{ width: `${progress}%` }} />
                        </div>
                        <span className="font-mono text-xs text-muted-foreground tabular">
                            hari {activePeriod.day}/{activePeriod.totalDays}
                        </span>
                    </div>

                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                        <span>
                            Costing <span className="font-medium text-foreground">{branch.costingMethod}</span>
                        </span>
                        <span>
                            Pembulatan{' '}
                            <span className="font-medium text-foreground">
                                {branch.roundingUnit} · {branch.roundingMode}
                            </span>
                        </span>
                    </div>
                </div>

                {/* ------------------------------------------------------ kartu KPI */}
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Omzet"
                        value={formatRupiahShort(kpi.omzet.value)}
                        delta={kpi.omzet.delta}
                        caption="dibanding kemarin"
                        icon={<Banknote className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Transaksi"
                        value={String(kpi.transaksi.value)}
                        delta={kpi.transaksi.delta}
                        caption="nota hari ini"
                        icon={<Receipt className="h-4 w-4" />}
                    />
                    <StatCard
                        label="HPP"
                        value={formatRupiahShort(kpi.hpp.value)}
                        delta={kpi.hpp.delta}
                        caption="harga pokok terjual"
                        icon={<Package className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Laba Kotor"
                        value={formatRupiahShort(kpi.labaKotor.value)}
                        delta={kpi.labaKotor.delta}
                        caption="omzet dikurangi HPP"
                        icon={<TrendingUp className="h-4 w-4" />}
                        tone="positive"
                    />
                </div>

                {/* ------------------------------------------- grafik & metode bayar */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel
                        title="Omzet & HPP"
                        description="14 hari terakhir"
                        className="lg:col-span-2"
                        action={
                            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-sm bg-primary/85" /> Omzet
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-sm bg-primary/25" /> HPP
                                </span>
                            </div>
                        }
                    >
                        <BarChart data={salesTrend} />
                    </Panel>

                    <Panel title="Metode Pembayaran" description="Hari ini">
                        <CompositionBar items={paymentMix.map((p) => ({ label: p.label, amount: p.amount }))} />
                    </Panel>
                </div>

                {/* --------------------------------------------- transaksi & produk */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel
                        title="Transaksi Terakhir"
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
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Nomor</TableHead>
                                    <TableHead className="label-eyebrow">Waktu</TableHead>
                                    <TableHead className="label-eyebrow">Kasir</TableHead>
                                    <TableHead className="label-eyebrow text-right">Total</TableHead>
                                    <TableHead className="label-eyebrow text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentSales.map((sale) => (
                                    <TableRow key={sale.id}>
                                        <TableCell className="font-mono text-xs">{sale.number}</TableCell>
                                        <TableCell className="text-muted-foreground">{sale.time}</TableCell>
                                        <TableCell>{sale.cashier}</TableCell>
                                        <TableCell className="text-right font-mono font-medium">
                                            {formatRupiah(sale.paidTotal)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <StatusBadge status={sale.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    <div className="flex flex-col gap-4">
                        <Panel title="Produk Terlaris" description="Hari ini">
                            <ul className="space-y-2.5">
                                {topProducts.map((p, i) => (
                                    <li key={p.name} className="flex items-center gap-2.5">
                                        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-muted font-mono text-2xs font-semibold text-muted-foreground">
                                            {i + 1}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{p.name}</p>
                                            <p className="font-mono text-2xs text-muted-foreground">
                                                {formatQty(p.qty)} {p.uom}
                                            </p>
                                        </div>
                                        <span className="font-mono text-sm tabular">{formatRupiahShort(p.omzet)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>

                        <Panel
                            title="Stok Menipis"
                            description={`${lowStock.length} produk di bawah minimum`}
                            action={<AlertTriangle className="h-4 w-4 text-warning" />}
                        >
                            <ul className="space-y-2.5">
                                {lowStock.map((p) => (
                                    <li key={p.id} className="flex items-center gap-2.5">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{p.name}</p>
                                            <p className="font-mono text-2xs text-muted-foreground">
                                                min {formatQty(p.minStock)} {p.baseUom}
                                            </p>
                                        </div>
                                        <span className="font-mono text-sm font-semibold text-warning tabular">
                                            {formatQty(p.stock)} {p.baseUom}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    </div>
                </div>

                {/* ----------------------------------------------------- aktivitas */}
                <Panel title="Aktivitas Terakhir" description="Jejak perubahan hari ini">
                    <ul className="divide-y">
                        {recentActivity.map((a, i) => (
                            <li key={i} className="flex items-baseline gap-3 py-2 first:pt-0 last:pb-0">
                                <span className="w-10 shrink-0 font-mono text-xs text-muted-foreground tabular">
                                    {a.time}
                                </span>
                                <span className="text-sm">
                                    <span className="font-medium">{a.user}</span>{' '}
                                    <span className="text-muted-foreground">{a.action}</span>{' '}
                                    <span className="font-mono text-xs">{a.target}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Coins className="h-3.5 w-3.5" />
                    Seluruh angka di halaman ini masih data dummy untuk keperluan tampilan.
                </p>
            </div>
        </AppLayout>
    );
}
