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
import { Ban, CircleDollarSign, HandCoins } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Piutang Usaha', href: '/piutang' },
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

interface BarisNota {
    name: string;
    qty: number;
    uom: string;
    unitPrice: number;
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

interface Piutang {
    id: number;
    saleNumber: string | null;
    date: string | null;
    customer: string | null;
    dueDate: string | null;
    overdue: boolean;
    amount: number;
    paidAmount: number;
    outstanding: number;
    status: 'OPEN' | 'PARTIAL' | 'PAID' | 'VOID';
    items: BarisNota[];
    payments: Pembayaran[];
}

export default function PiutangIndex() {
    const { receivables, filters, summary, today, errors } = usePage().props as unknown as {
        receivables: Paginated<Piutang>;
        filters: { status: string };
        summary: { count: number; outstanding: number; overdue: number };
        today: string;
        errors: Record<string, string>;
    };

    const [detail, setDetail] = useState<Piutang | null>(null);
    const [terimaTerbuka, setTerimaTerbuka] = useState(false);
    const [batalTerimaTarget, setBatalTerimaTarget] = useState<Pembayaran | null>(null);

    const terimaForm = useForm({
        payment_date: today,
        amount: '',
        payment_type: 'CASH',
    });

    const batalTerimaForm = useForm({});

    function gantiStatus(status: string) {
        router.get('/piutang', { status }, { preserveState: true, preserveScroll: true });
    }

    function bukaTerima() {
        if (!detail) return;
        terimaForm.setData({ payment_date: today, amount: String(detail.outstanding), payment_type: 'CASH' });
        terimaForm.clearErrors();
        setTerimaTerbuka(true);
    }

    function simpanTerima() {
        if (!detail) return;
        terimaForm.post(`/piutang/${detail.id}/terima`, {
            preserveScroll: true,
            onSuccess: () => {
                setTerimaTerbuka(false);
                setDetail(null);
            },
        });
    }

    function bukaBatalTerima(pembayaran: Pembayaran) {
        batalTerimaForm.clearErrors();
        setBatalTerimaTarget(pembayaran);
    }

    function batalkanTerima() {
        if (!batalTerimaTarget) return;
        batalTerimaForm.post(`/piutang/pembayaran/${batalTerimaTarget.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerimaTarget(null);
                setDetail(null);
            },
        });
    }

    const kartu = [
        { label: 'Piutang terbuka', value: String(summary.count) },
        { label: 'Total piutang', value: formatRupiah(summary.outstanding) },
        { label: 'Sudah jatuh tempo', value: formatRupiah(summary.overdue), warn: summary.overdue > 0 },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Piutang Usaha" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader title="Piutang Usaha" subtitle="Lahir otomatis dari nota kasir berbayar kredit." />

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

                <Panel title="Daftar Piutang" description={`${receivables.total} baris piutang`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">No. Nota</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Pelanggan</TableHead>
                                    <TableHead className="w-32">Jatuh Tempo</TableHead>
                                    <TableHead className="w-28 text-right">Nilai</TableHead>
                                    <TableHead className="w-28 text-right">Sisa</TableHead>
                                    <TableHead className="w-28">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receivables.data.length === 0 && (
                                    <EmptyRow colSpan={7} icon={<HandCoins />} message="Tidak ada piutang pada saringan ini." />
                                )}

                                {receivables.data.map((r) => (
                                    <TableRow
                                        key={r.id}
                                        onClick={() => setDetail(r)}
                                        className={cn('cursor-pointer', r.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{r.saleNumber ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{r.date ? formatDate(r.date) : '—'}</TableCell>
                                        <TableCell className="text-xs">{r.customer ?? '—'}</TableCell>
                                        <TableCell className="text-2xs">
                                            {r.dueDate ? formatDate(r.dueDate) : '—'}
                                            {r.overdue && (
                                                <Chip tone="warning">
                                                    <span className="ml-1">Terlambat</span>
                                                </Chip>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(r.amount)}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs font-medium">
                                            {formatRupiah(r.outstanding)}
                                        </TableCell>
                                        <TableCell>
                                            <Chip tone={TONE_STATUS[r.status]}>{LABEL_STATUS[r.status]}</Chip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={receivables} />
                </Panel>
            </div>

            {/* ========================================================= dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <HandCoins className="h-4 w-4" />
                            {detail?.saleNumber ?? 'Piutang'}
                            {detail && <Chip tone={TONE_STATUS[detail.status]}>{LABEL_STATUS[detail.status]}</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {detail.date ? formatDate(detail.date) : '—'} · {detail.customer ?? '—'}
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
                                                {formatQty(l.qty)} {l.uom} × {formatRupiah(l.unitPrice)}
                                            </span>
                                        </span>
                                        <span className="tabular shrink-0 font-mono">{formatRupiah(l.lineTotal)}</span>
                                    </li>
                                ))}
                            </ul>

                            <dl className="space-y-1">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Nilai nota</dt>
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
                                                    <span
                                                        className={cn(
                                                            'block font-mono',
                                                            pay.status === 'VOID' && 'text-muted-foreground line-through',
                                                        )}
                                                    >
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
                                                            onClick={() => bukaBatalTerima(pay)}
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
                            <Button size="sm" onClick={bukaTerima}>
                                <CircleDollarSign className="mr-1 h-3.5 w-3.5" /> Terima Pembayaran
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================= dialog terima */}
            <Dialog open={terimaTerbuka} onOpenChange={setTerimaTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Terima pembayaran {detail?.saleNumber}</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Sisa saat ini {detail && formatRupiah(detail.outstanding)}. Boleh menerima sebagian.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Tanggal terima" required error={terimaForm.errors.payment_date}>
                            <Input
                                type="date"
                                value={terimaForm.data.payment_date}
                                onChange={(e) => terimaForm.setData('payment_date', e.target.value)}
                                className="h-8"
                            />
                        </Field>

                        <Field label="Nilai" required error={terimaForm.errors.amount}>
                            <Input
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={detail?.outstanding}
                                value={terimaForm.data.amount}
                                onChange={(e) => terimaForm.setData('amount', e.target.value)}
                                className="h-8 text-right font-mono"
                            />
                        </Field>

                        <Field label="Metode bayar" required error={terimaForm.errors.payment_type}>
                            <NativeSelect
                                value={terimaForm.data.payment_type}
                                onChange={(v) => terimaForm.setData('payment_type', v)}
                                options={Object.entries(LABEL_METODE).map(([value, label]) => ({ value, label }))}
                            />
                        </Field>

                        {errors?.receivable && <p className="text-2xs text-destructive font-medium">{errors.receivable}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setTerimaTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanTerima} disabled={terimaForm.processing}>
                            {terimaForm.processing ? 'Menyimpan...' : 'Simpan penerimaan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* =================================================== dialog batal terima */}
            <Dialog open={!!batalTerimaTarget} onOpenChange={(open) => !open && setBatalTerimaTarget(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {batalTerimaTarget?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Piutang kembali terbuka sebesar nilai pembayaran ini. Bila bermetode tunai, kasnya ditarik lagi pada tanggal
                            pembatalan — tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.payment && <p className="text-2xs text-destructive font-medium">{errors.payment}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerimaTarget(null)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkanTerima} disabled={batalTerimaForm.processing}>
                            {batalTerimaForm.processing ? 'Membatalkan...' : 'Ya, batalkan pembayaran'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
