import { Chip, Field, FlashMessage, NativeSelect, ServerPagination, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, CircleDollarSign, Landmark } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Utang Usaha', href: '/utang' },
];

const LABEL_STATUS: Record<string, string> = {
    OPEN: 'Belum Dibayar',
    PARTIAL: 'Sebagian',
    PAID: 'Lunas',
    VOID: 'Dibatalkan',
};

const TONE_STATUS: Record<string, 'neutral' | 'accent' | 'warning'> = {
    OPEN: 'warning',
    PARTIAL: 'warning',
    PAID: 'accent',
    VOID: 'neutral',
};

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

const SARINGAN: { key: string; label: string }[] = [
    { key: 'OUTSTANDING', label: 'Belum Lunas' },
    { key: 'PAID', label: 'Lunas' },
    { key: 'VOID', label: 'Dibatalkan' },
    { key: 'ALL', label: 'Semua' },
];

interface BarisFaktur {
    name: string;
    qty: number;
    uom: string;
    unitCost: number;
    lineTotal: number;
}

interface Pembayaran {
    id: number;
    number: string;
    date: string;
    amount: number;
    paymentType: string;
    status: 'POSTED' | 'VOID';
}

interface Utang {
    id: number;
    purchaseNumber: string | null;
    date: string | null;
    supplier: string | null;
    dueDate: string | null;
    overdue: boolean;
    amount: number;
    paidAmount: number;
    outstanding: number;
    status: 'OPEN' | 'PARTIAL' | 'PAID' | 'VOID';
    items: BarisFaktur[];
    payments: Pembayaran[];
}

export default function UtangIndex() {
    const { payables, filters, summary, today, errors } = usePage().props as unknown as {
        payables: Paginated<Utang>;
        filters: { status: string };
        summary: { count: number; outstanding: number; overdue: number };
        today: string;
        errors: Record<string, string>;
    };

    const [detail, setDetail] = useState<Utang | null>(null);
    const [bayarTerbuka, setBayarTerbuka] = useState(false);
    const [batalBayarTarget, setBatalBayarTarget] = useState<Pembayaran | null>(null);

    const bayarForm = useForm({
        payment_date: today,
        amount: '',
        payment_type: 'CASH',
    });

    const batalBayarForm = useForm({});

    function gantiStatus(status: string) {
        router.get('/utang', { status }, { preserveState: true, preserveScroll: true });
    }

    function bukaBayar() {
        if (!detail) return;
        bayarForm.setData({ payment_date: today, amount: String(detail.outstanding), payment_type: 'CASH' });
        bayarForm.clearErrors();
        setBayarTerbuka(true);
    }

    function simpanBayar() {
        if (!detail) return;
        bayarForm.post(`/utang/${detail.id}/bayar`, {
            preserveScroll: true,
            onSuccess: () => {
                setBayarTerbuka(false);
                setDetail(null);
            },
        });
    }

    function bukaBatalBayar(pembayaran: Pembayaran) {
        batalBayarForm.clearErrors();
        setBatalBayarTarget(pembayaran);
    }

    function batalkanBayar() {
        if (!batalBayarTarget) return;
        batalBayarForm.post(`/utang/pembayaran/${batalBayarTarget.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalBayarTarget(null);
                setDetail(null);
            },
        });
    }

    const kartu = [
        { label: 'Utang terbuka', value: String(summary.count) },
        { label: 'Total terutang', value: formatRupiah(summary.outstanding) },
        { label: 'Sudah jatuh tempo', value: formatRupiah(summary.overdue), warn: summary.overdue > 0 },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Utang Usaha" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader title="Utang Usaha" subtitle="Lahir otomatis dari faktur pemasok yang diposting." />

                <FlashMessage />

                <div className="grid gap-3 sm:grid-cols-3">
                    {kartu.map((k) => (
                        <div key={k.label} className="bg-card rounded-lg border px-4 py-3">
                            <p className="label-eyebrow">{k.label}</p>
                            <p className={cn('tabular mt-1 font-mono text-xl font-semibold', k.warn && 'text-destructive')}>{k.value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap gap-1">
                    {SARINGAN.map((f) => (
                        <button
                            key={f.key}
                            onClick={() => gantiStatus(f.key)}
                            className={cn(
                                'rounded-md border px-2.5 py-1 text-xs transition-colors',
                                filters.status === f.key ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent',
                            )}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>

                <Panel title="Daftar Utang" description={`${payables.total} baris utang`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">No. Faktur</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Pemasok</TableHead>
                                    <TableHead className="w-32">Jatuh Tempo</TableHead>
                                    <TableHead className="w-28 text-right">Nilai</TableHead>
                                    <TableHead className="w-28 text-right">Sisa</TableHead>
                                    <TableHead className="w-28">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payables.data.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<Landmark />} message="Tidak ada utang pada saringan ini." />
                                )}

                                {payables.data.map((p) => (
                                    <TableRow
                                        key={p.id}
                                        onClick={() => setDetail(p)}
                                        className={cn('cursor-pointer', p.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{p.purchaseNumber ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{p.date ? formatDate(p.date) : '—'}</TableCell>
                                        <TableCell className="text-xs">{p.supplier ?? '—'}</TableCell>
                                        <TableCell className="text-2xs">
                                            {p.dueDate ? formatDate(p.dueDate) : '—'}
                                            {p.overdue && (
                                                <Chip tone="warning">
                                                    <span className="ml-1">Terlambat</span>
                                                </Chip>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(p.amount)}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs font-medium">
                                            {formatRupiah(p.outstanding)}
                                        </TableCell>
                                        <TableCell>
                                            <Chip tone={TONE_STATUS[p.status]}>{LABEL_STATUS[p.status]}</Chip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={payables} />
                </Panel>
            </div>

            {/* ========================================================= dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Landmark className="h-4 w-4" />
                            {detail?.purchaseNumber ?? 'Utang'}
                            {detail && <Chip tone={TONE_STATUS[detail.status]}>{LABEL_STATUS[detail.status]}</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {detail.date ? formatDate(detail.date) : '—'} · {detail.supplier ?? '—'}
                                    {detail.dueDate && <> · jatuh tempo {formatDate(detail.dueDate)}</>}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            <ul className="divide-y border-y">
                                {detail.items.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                {formatQty(l.qty)} {l.uom} × {formatRupiah(l.unitCost)}
                                            </span>
                                        </span>
                                        <span className="tabular shrink-0 font-mono">{formatRupiah(l.lineTotal)}</span>
                                    </li>
                                ))}
                            </ul>

                            <dl className="space-y-1">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Nilai faktur</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.amount)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Sudah dibayar</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.paidAmount)}</dd>
                                </div>
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Sisa</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.outstanding)}</dd>
                                </div>
                            </dl>

                            {detail.payments.length > 0 && (
                                <div className="space-y-1.5">
                                    <span className="label-eyebrow">Riwayat pembayaran</span>
                                    <ul className="divide-y rounded-md border">
                                        {detail.payments.map((pay) => (
                                            <li key={pay.id} className="flex items-center justify-between gap-2 px-2.5 py-1.5">
                                                <span className="min-w-0">
                                                    <span className={cn('block font-mono', pay.status === 'VOID' && 'text-muted-foreground line-through')}>
                                                        {pay.number}
                                                    </span>
                                                    <span className="text-2xs text-muted-foreground">
                                                        {formatDate(pay.date)} · {LABEL_METODE[pay.paymentType] ?? pay.paymentType}
                                                    </span>
                                                </span>
                                                <span className="flex shrink-0 items-center gap-2">
                                                    <span className="tabular font-mono">{formatRupiah(pay.amount)}</span>
                                                    {pay.status === 'VOID' ? (
                                                        <Chip tone="neutral">Dibatalkan</Chip>
                                                    ) : (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground hover:text-destructive h-6 w-6"
                                                            onClick={() => bukaBatalBayar(pay)}
                                                            aria-label="Batalkan pembayaran"
                                                            title="Batalkan pembayaran"
                                                        >
                                                            <Ban className="h-3.5 w-3.5" />
                                                        </Button>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {(detail?.status === 'OPEN' || detail?.status === 'PARTIAL') && (
                            <Button size="sm" onClick={bukaBayar}>
                                <CircleDollarSign className="mr-1 h-3.5 w-3.5" /> Bayar
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================== dialog bayar */}
            <Dialog open={bayarTerbuka} onOpenChange={setBayarTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Bayar {detail?.purchaseNumber}</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Sisa saat ini {detail && formatRupiah(detail.outstanding)}. Boleh membayar sebagian.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Tanggal bayar" required error={bayarForm.errors.payment_date}>
                            <Input
                                type="date"
                                value={bayarForm.data.payment_date}
                                onChange={(e) => bayarForm.setData('payment_date', e.target.value)}
                                className="h-8"
                            />
                        </Field>

                        <Field label="Nilai" required error={bayarForm.errors.amount}>
                            <Input
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={detail?.outstanding}
                                value={bayarForm.data.amount}
                                onChange={(e) => bayarForm.setData('amount', e.target.value)}
                                className="h-8 text-right font-mono"
                            />
                        </Field>

                        <Field label="Metode bayar" required error={bayarForm.errors.payment_type}>
                            <NativeSelect
                                value={bayarForm.data.payment_type}
                                onChange={(v) => bayarForm.setData('payment_type', v)}
                                options={Object.entries(LABEL_METODE).map(([value, label]) => ({ value, label }))}
                            />
                        </Field>

                        {errors?.payable && <p className="text-2xs text-destructive font-medium">{errors.payable}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBayarTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanBayar} disabled={bayarForm.processing}>
                            {bayarForm.processing ? 'Menyimpan...' : 'Simpan pembayaran'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* =================================================== dialog batal bayar */}
            <Dialog open={!!batalBayarTarget} onOpenChange={(open) => !open && setBatalBayarTarget(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {batalBayarTarget?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Utang kembali terbuka sebesar nilai pembayaran ini. Bila bermetode tunai, kasnya dikembalikan pada tanggal pembatalan
                            — tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.payment && <p className="text-2xs text-destructive font-medium">{errors.payment}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalBayarTarget(null)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkanBayar} disabled={batalBayarForm.processing}>
                            {batalBayarForm.processing ? 'Membatalkan...' : 'Ya, batalkan pembayaran'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
