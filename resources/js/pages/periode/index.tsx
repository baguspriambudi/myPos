import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarRange, CheckCircle2, CircleAlert, Lock, TriangleAlert } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PageHeader, Panel, StatusBadge, formatDate, formatRupiah, formatRupiahShort } from '@/components/pos-ui';
import { activePeriod, fiscalPeriods, preClosingChecks } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Keuangan', href: '#' },
    { title: 'Periode', href: '/periode' },
];

const CHECK_ICON = {
    ok: { icon: CheckCircle2, cls: 'text-success' },
    warn: { icon: TriangleAlert, cls: 'text-warning' },
    error: { icon: CircleAlert, cls: 'text-destructive' },
};

export default function PeriodeIndex() {
    const [closingOpen, setClosingOpen] = useState(false);

    const blocker = preClosingChecks.filter((c) => c.status === 'error');
    const warning = preClosingChecks.filter((c) => c.status === 'warn');
    const progress = Math.round((activePeriod.day / activePeriod.totalDays) * 100);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Periode" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Fiscal Period"
                    subtitle="Periode menentukan transaksi mana yang masih boleh diubah. Setelah ditutup, angkanya final."
                    action={
                        <Button size="sm" onClick={() => setClosingOpen(true)}>
                            <Lock className="mr-1 h-3.5 w-3.5" /> Tutup Periode
                        </Button>
                    }
                />

                {/* ------------------------------------------------ periode aktif */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Periode Berjalan" className="lg:col-span-2">
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-4">
                            <div>
                                <p className="label-eyebrow">Kode</p>
                                <p className="mt-0.5 flex items-center gap-2 font-mono text-lg font-semibold">
                                    {activePeriod.code}
                                    <StatusBadge status={activePeriod.status} />
                                </p>
                            </div>
                            <div>
                                <p className="label-eyebrow">Rentang</p>
                                <p className="mt-0.5 text-sm">
                                    {formatDate(activePeriod.startDate)} – {formatDate(activePeriod.endDate)}
                                </p>
                            </div>
                            <div className="min-w-40 flex-1">
                                <p className="label-eyebrow">Progres</p>
                                <div className="mt-1.5 flex items-center gap-2.5">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div className="h-full rounded-full bg-primary" style={{ width: `${progress}%` }} />
                                    </div>
                                    <span className="font-mono text-xs text-muted-foreground tabular">
                                        {activePeriod.day}/{activePeriod.totalDays}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <dl className="mt-5 grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-4">
                            {[
                                { label: 'Penjualan', value: fiscalPeriods[0].netSales },
                                { label: 'HPP', value: fiscalPeriods[0].cogs },
                                { label: 'Laba kotor', value: fiscalPeriods[0].grossProfit },
                                { label: 'Laba bersih', value: fiscalPeriods[0].netProfit },
                            ].map((item) => (
                                <div key={item.label}>
                                    <dt className="label-eyebrow">{item.label}</dt>
                                    <dd className="mt-0.5 font-mono text-lg font-semibold tabular">
                                        {formatRupiahShort(item.value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>

                    {/* --------------------------------------- pemeriksaan penutupan */}
                    <Panel
                        title="Pemeriksaan Penutupan"
                        description={
                            blocker.length > 0
                                ? `${blocker.length} hal harus diselesaikan dulu`
                                : 'Siap ditutup'
                        }
                    >
                        <ul className="space-y-2.5">
                            {preClosingChecks.map((c) => {
                                const { icon: Icon, cls } = CHECK_ICON[c.status];
                                return (
                                    <li key={c.label} className="flex gap-2.5">
                                        <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', cls)} />
                                        <div className="min-w-0">
                                            <p className="text-sm leading-snug">{c.label}</p>
                                            <p className="text-2xs text-muted-foreground">{c.detail}</p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>
                </div>

                {/* -------------------------------------------------- riwayat */}
                <Panel title="Riwayat Periode" bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Periode</TableHead>
                                    <TableHead className="label-eyebrow">Rentang</TableHead>
                                    <TableHead className="label-eyebrow">Costing</TableHead>
                                    <TableHead className="label-eyebrow text-right">Transaksi</TableHead>
                                    <TableHead className="label-eyebrow text-right">Penjualan</TableHead>
                                    <TableHead className="label-eyebrow text-right">Laba Bersih</TableHead>
                                    <TableHead className="label-eyebrow">Ditutup</TableHead>
                                    <TableHead className="label-eyebrow text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {fiscalPeriods.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <p className="font-mono text-xs font-medium">{p.code}</p>
                                            <p className="text-2xs text-muted-foreground">{p.name}</p>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(p.startDate)} – {formatDate(p.endDate)}
                                        </TableCell>
                                        <TableCell>
                                            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-2xs">
                                                {p.costingMethod}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {p.transactions.toLocaleString('id-ID')}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">{formatRupiah(p.netSales)}</TableCell>
                                        <TableCell className="text-right font-mono font-medium">
                                            {formatRupiah(p.netProfit)}
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">
                                            {p.closedAt ? (
                                                <>
                                                    {p.closedAt}
                                                    <br />
                                                    <span className="text-2xs">{p.closedBy}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <StatusBadge status={p.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Panel>
            </div>

            {/* ------------------------------------------------ dialog penutupan */}
            <Dialog open={closingOpen} onOpenChange={setClosingOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CalendarRange className="h-4 w-4" />
                            Tutup {activePeriod.code}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Setelah ditutup, seluruh transaksi periode ini terkunci. Perubahan hanya mungkin lewat
                            proses buka ulang yang memerlukan alasan dan persetujuan.
                        </p>

                        <ul className="space-y-2 rounded-md border p-3">
                            {preClosingChecks.map((c) => {
                                const { icon: Icon, cls } = CHECK_ICON[c.status];
                                return (
                                    <li key={c.label} className="flex items-start gap-2.5 text-sm">
                                        <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', cls)} />
                                        <span className="flex-1 leading-snug">{c.label}</span>
                                        <span className="text-2xs text-muted-foreground">{c.detail}</span>
                                    </li>
                                );
                            })}
                        </ul>

                        {blocker.length > 0 && (
                            <p className="flex items-start gap-2 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                                <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                Penutupan diblokir sampai {blocker.length} hal di atas diselesaikan.
                            </p>
                        )}
                        {blocker.length === 0 && warning.length > 0 && (
                            <p className="flex items-start gap-2 rounded-md bg-warning/10 px-3 py-2 text-xs">
                                <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                                Ada {warning.length} peringatan. Penutupan tetap bisa dilanjutkan.
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setClosingOpen(false)}>
                            Batal
                        </Button>
                        <Button size="sm" disabled={blocker.length > 0}>
                            <Lock className="mr-1 h-3.5 w-3.5" /> Jalankan Penutupan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
