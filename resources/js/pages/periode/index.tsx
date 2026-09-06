import { Chip, Field, FlashMessage, NativeSelect } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatRupiah, formatRupiahShort } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CalendarRange, CheckCircle2, CircleAlert, Lock, LockOpen, Plus, Scale, TriangleAlert, X } from 'lucide-react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Periode Pembukuan', href: '/periode' },
];

const LABEL_STATUS: Record<string, string> = {
    DRAFT: 'Draf',
    OPEN: 'Terbuka',
    CLOSING: 'Sedang Ditutup',
    CLOSED: 'Ditutup',
    REOPENED: 'Dibuka Ulang',
};

const TONE_STATUS: Record<string, 'neutral' | 'accent' | 'warning'> = {
    DRAFT: 'neutral',
    OPEN: 'accent',
    CLOSING: 'warning',
    CLOSED: 'neutral',
    REOPENED: 'warning',
};

const IKON_PERIKSA = {
    ok: { icon: CheckCircle2, cls: 'text-success' },
    warn: { icon: TriangleAlert, cls: 'text-warning' },
    error: { icon: CircleAlert, cls: 'text-destructive' },
};

const LABEL_RESOLUSI: Record<string, string> = {
    CASH_ADJUSTMENT: 'Penyesuaian kas',
    CASHIER_CHARGE: 'Ditanggung kasir',
    OTHER_EXPENSE: 'Biaya lain',
    OTHER_INCOME: 'Pendapatan lain',
};

interface Satuan {
    id: number;
    name: string;
    factor: number;
}

interface Produk {
    id: number;
    name: string;
    sku: string;
    isWeighted: boolean;
    stock: number;
    uoms: Satuan[];
}

interface Periksa {
    key: string;
    label: string;
    detail: string;
    status: 'ok' | 'warn' | 'error';
    overridable: boolean;
    approved: boolean;
}

interface Periode {
    id: number;
    code: string;
    name: string;
    branch: string | null;
    startDate: string;
    endDate: string;
    status: keyof typeof LABEL_STATUS;
    costingMethod: string;
    closedAt: string | null;
    closedBy: string | null;
    netSales: number | null;
    cogs: number | null;
    grossProfit: number | null;
    netProfit: number | null;
    snapshotVersion: number | null;
}

interface Snapshot {
    version: number;
    openingInventory: number;
    purchase: number;
    purchaseReturn: number;
    salesCost: number;
    salesReturnCost: number;
    adjustment: number;
    endingInventory: number;
    grossSales: number;
    netSales: number;
    cogs: number;
    grossProfit: number;
    expense: number;
    netProfit: number;
    openingCash: number;
    cashSales: number;
    cashIn: number;
    cashOut: number;
    refund: number;
    endingCash: number;
    cashDifference: number;
}

interface PeriodeAktif extends Periode {
    checks: Periksa[];
    openingSaved: boolean;
    isFirst: boolean;
    affected: { code: string; status: string }[];
    snapshot: Snapshot | null;
}

interface Permintaan {
    id: number;
    periodCode: string;
    reason: string;
    status: 'PENDING' | 'APPROVED' | 'REJECTED';
    approvalType: string;
    requestedBy: string | null;
    requestedAt: string | null;
    reviewedBy: string | null;
    isMine: boolean;
}

interface Selisih {
    id: number;
    periodCode: string | null;
    shiftNumber: string | null;
    cashier: string | null;
    expected: number;
    actual: number;
    difference: number;
    status: 'OPEN' | 'RESOLVED';
    resolutionType: string | null;
    resolvedBy: string | null;
    note: string | null;
    isMine: boolean;
}

/** Locale aplikasi masih 'en', jadi nama bulan ditulis sendiri di sini juga. */
const BULAN = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

interface BarisOpening {
    key: number;
    product_id: string;
    uom_id: string;
    qty: string;
    unit_cost: string;
}

export default function PeriodeIndex() {
    const { periods, active, requests, differences, products, branches, periodDefaults, activeBranch, canApprove, today, errors } = usePage()
        .props as unknown as {
        periods: Periode[];
        active: PeriodeAktif | null;
        requests: Permintaan[];
        differences: Selisih[];
        products: Produk[];
        branches: { id: number; name: string }[];
        periodDefaults: Record<string, { startDate: string; endDate: string }>;
        activeBranch: { id: number; name: string } | null;
        canApprove: boolean;
        today: string;
        errors: Record<string, string>;
    };

    const [buatTerbuka, setBuatTerbuka] = useState(false);
    const [openingTerbuka, setOpeningTerbuka] = useState(false);
    const [tutupTerbuka, setTutupTerbuka] = useState(false);
    const [bukaUlangTarget, setBukaUlangTarget] = useState<Periode | null>(null);
    const [selisihTarget, setSelisihTarget] = useState<Selisih | null>(null);

    const kunciBaris = useRef(0);
    const [rows, setRows] = useState<BarisOpening[]>([]);

    const buatForm = useForm({
        branch_id: String(activeBranch?.id ?? ''),
        start_date: today,
        end_date: today,
    });
    const openingForm = useForm({
        lines: [] as { product_id: number; uom_id: number; qty: number; unit_cost: number }[],
        opening_cash: '',
        opening_receivable: '',
        opening_payable: '',
        note: '',
    });
    const bukaForm = useForm({});
    const tutupForm = useForm({ overrides: [] as string[], override_reason: '' });
    const bukaUlangForm = useForm({ reason: '' });
    const tinjauForm = useForm({});
    const selisihForm = useForm({ resolution_type: 'CASH_ADJUSTMENT', note: '' });

    const blocker = (active?.checks ?? []).filter((c) => c.status === 'error' && !tutupForm.data.overrides.includes(c.key));
    const peringatan = (active?.checks ?? []).filter((c) => c.status === 'warn');
    const bisaDitembus = (active?.checks ?? []).filter((c) => c.status === 'error' && c.overridable);

    function barisBaru(): BarisOpening {
        kunciBaris.current += 1;
        return { key: kunciBaris.current, product_id: '', uom_id: '', qty: '', unit_cost: '' };
    }

    /**
     * Rentang yang disarankan untuk satu lokasi: menyambung periode terakhir,
     * atau tanggal 1 sampai akhir bulan ini bila belum ada periode sama sekali.
     */
    function saranUntuk(branchId: string) {
        return periodDefaults[branchId] ?? { startDate: today, endDate: today };
    }

    function bukaDialogBuat() {
        const kunci = String(activeBranch?.id ?? '');
        const saran = saranUntuk(kunci);
        buatForm.setData({ branch_id: kunci, start_date: saran.startDate, end_date: saran.endDate });
        buatForm.clearErrors();
        setBuatTerbuka(true);
    }

    // Berganti lokasi berarti berganti riwayat periode; tanggalnya ikut disetel
    // ulang supaya saran yang tampil selalu milik lokasi yang benar.
    function gantiLokasiPeriode(branchId: string) {
        const saran = saranUntuk(branchId);
        buatForm.setData({ branch_id: branchId, start_date: saran.startDate, end_date: saran.endDate });
    }

    function simpanPeriode() {
        // Kosong berarti periode tingkat usaha: berlaku di seluruh lokasi yang
        // belum punya periode khusus (lihat FiscalPeriodResolver).
        buatForm.transform((data) => ({ ...data, branch_id: data.branch_id === '' ? null : Number(data.branch_id) }));
        buatForm.post('/periode', { preserveScroll: true, onSuccess: () => setBuatTerbuka(false) });
    }

    function bukaDialogOpening() {
        openingForm.setData({ lines: [], opening_cash: '', opening_receivable: '', opening_payable: '', note: '' });
        openingForm.clearErrors();
        setRows([barisBaru()]);
        setOpeningTerbuka(true);
    }

    function pilihProduk(key: number, productId: string) {
        const produk = products.find((p) => p.id === Number(productId));
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, product_id: productId, uom_id: String(produk?.uoms[0]?.id ?? '') } : r)));
    }

    function ubahBaris(key: number, field: 'uom_id' | 'qty' | 'unit_cost', value: string) {
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, [field]: value } : r)));
    }

    const barisValid = rows.filter((r) => r.product_id && r.uom_id && r.qty !== '' && r.unit_cost !== '');

    function simpanOpening() {
        if (!active) return;
        openingForm.transform((data) => ({
            ...data,
            lines: barisValid.map((r) => ({
                product_id: Number(r.product_id),
                uom_id: Number(r.uom_id),
                qty: Number(r.qty),
                unit_cost: Number(r.unit_cost),
            })),
        }));
        openingForm.post(`/periode/${active.id}/opening`, { preserveScroll: true, onSuccess: () => setOpeningTerbuka(false) });
    }

    function bukaPeriode() {
        if (!active) return;
        bukaForm.post(`/periode/${active.id}/buka`, { preserveScroll: true });
    }

    function bukaDialogTutup() {
        tutupForm.setData({ overrides: [], override_reason: '' });
        tutupForm.clearErrors();
        setTutupTerbuka(true);
    }

    function ubahOverride(key: string) {
        tutupForm.setData(
            'overrides',
            tutupForm.data.overrides.includes(key)
                ? tutupForm.data.overrides.filter((k) => k !== key)
                : [...tutupForm.data.overrides, key],
        );
    }

    function tutupPeriode() {
        if (!active) return;
        tutupForm.post(`/periode/${active.id}/tutup`, { preserveScroll: true, onSuccess: () => setTutupTerbuka(false) });
    }

    function ajukanBukaUlang() {
        if (!bukaUlangTarget) return;
        bukaUlangForm.post(`/periode/${bukaUlangTarget.id}/buka-ulang`, {
            preserveScroll: true,
            onSuccess: () => setBukaUlangTarget(null),
        });
    }

    function tinjau(id: number, keputusan: 'setujui' | 'tolak') {
        tinjauForm.post(`/periode/buka-ulang/${id}/${keputusan}`, { preserveScroll: true });
    }

    function bukaDialogSelisih(selisih: Selisih) {
        selisihForm.setData({ resolution_type: selisih.difference > 0 ? 'OTHER_INCOME' : 'CASH_ADJUSTMENT', note: '' });
        selisihForm.clearErrors();
        setSelisihTarget(selisih);
    }

    function selesaikanSelisih() {
        if (!selisihTarget) return;
        selisihForm.post(`/periode/selisih-kas/${selisihTarget.id}/selesaikan`, {
            preserveScroll: true,
            onSuccess: () => setSelisihTarget(null),
        });
    }

    const pilihanResolusi = Object.entries(LABEL_RESOLUSI)
        .filter(([value]) => {
            if (!selisihTarget) return true;
            if (selisihTarget.difference > 0) return value !== 'CASHIER_CHARGE' && value !== 'OTHER_EXPENSE';
            return value !== 'OTHER_INCOME';
        })
        .map(([value, label]) => ({ value, label }));

    // Pratinjau kode dan nama. Server yang menentukan hasil akhirnya — ia
    // menambah akhiran urut bila kode itu sudah terpakai.
    const mulai = buatForm.data.start_date;
    const pratinjauKode = mulai ? `FP-${mulai.slice(0, 7)}` : '—';
    const pratinjauNama = mulai ? `${BULAN[Number(mulai.slice(5, 7)) - 1] ?? ''} ${mulai.slice(0, 4)}` : '—';

    const selisihTerbuka = differences.filter((d) => d.status === 'OPEN');
    const permintaanMenggantung = requests.filter((r) => r.status === 'PENDING');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Periode Pembukuan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Periode Pembukuan"
                    subtitle="Periode menentukan transaksi mana yang masih boleh diubah. Setelah ditutup, angkanya final."
                    action={
                        <Button size="sm" variant="outline" onClick={bukaDialogBuat}>
                            <Plus className="mr-1 h-3.5 w-3.5" /> Periode Baru
                        </Button>
                    }
                />

                <FlashMessage />

                {active === null && (
                    <Panel title="Belum ada periode">
                        <p className="text-muted-foreground text-sm">
                            Buat periode pertama lebih dulu. Tanpa periode, seluruh transaksi ditolak karena tidak punya tempat
                            bernaung.
                        </p>
                    </Panel>
                )}

                {active !== null && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        {/* -------------------------------------------- periode berjalan */}
                        <Panel title="Periode Berjalan" className="lg:col-span-2">
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-4">
                                <div>
                                    <p className="label-eyebrow">Kode</p>
                                    <p className="mt-0.5 flex items-center gap-2 font-mono text-lg font-semibold">
                                        {active.code}
                                        <Chip tone={TONE_STATUS[active.status]}>{LABEL_STATUS[active.status]}</Chip>
                                    </p>
                                </div>
                                <div>
                                    <p className="label-eyebrow">Rentang</p>
                                    <p className="mt-0.5 text-sm">
                                        {formatDate(active.startDate)} – {formatDate(active.endDate)}
                                    </p>
                                </div>
                                <div>
                                    <p className="label-eyebrow">Lokasi</p>
                                    <p className="mt-0.5 text-sm">{active.branch ?? 'Seluruh lokasi'}</p>
                                </div>
                                <div>
                                    <p className="label-eyebrow">Costing</p>
                                    <p className="mt-0.5 font-mono text-sm">{active.costingMethod}</p>
                                </div>
                            </div>

                            {active.snapshot !== null && (
                                <dl className="mt-5 grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-4">
                                    {[
                                        { label: 'Penjualan bersih', value: active.snapshot.netSales },
                                        { label: 'HPP', value: active.snapshot.cogs },
                                        { label: 'Laba kotor', value: active.snapshot.grossProfit },
                                        { label: 'Laba bersih', value: active.snapshot.netProfit },
                                    ].map((item) => (
                                        <div key={item.label}>
                                            <dt className="label-eyebrow">{item.label}</dt>
                                            <dd className="tabular mt-0.5 font-mono text-lg font-semibold">
                                                {formatRupiahShort(item.value)}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}

                            {active.status === 'DRAFT' && (
                                <div className="mt-5 space-y-3 border-t pt-4">
                                    <p className="text-muted-foreground text-xs leading-relaxed">
                                        {active.isFirst
                                            ? 'Periode pertama: isi saldo awal lebih dulu — stok fisik, uang laci, serta piutang dan utang yang sudah berjalan. Setelah periode dibuka, angka ini hanya bisa dikoreksi lewat penyesuaian stok.'
                                            : 'Periode ini mewarisi saldo awal dari snapshot periode sebelumnya, jadi tidak perlu diisi manual.'}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {active.isFirst && !active.openingSaved && (
                                            <Button size="sm" variant="outline" onClick={bukaDialogOpening} disabled={activeBranch === null}>
                                                <Scale className="mr-1 h-3.5 w-3.5" /> Isi Saldo Awal
                                            </Button>
                                        )}
                                        {active.isFirst && active.openingSaved && (
                                            <Chip tone="accent">Saldo awal tersimpan</Chip>
                                        )}
                                        <Button size="sm" onClick={bukaPeriode} disabled={bukaForm.processing}>
                                            <LockOpen className="mr-1 h-3.5 w-3.5" />
                                            {bukaForm.processing ? 'Membuka...' : 'Buka Periode'}
                                        </Button>
                                    </div>
                                    {errors?.period && <p className="text-2xs text-destructive font-medium">{errors.period}</p>}
                                </div>
                            )}

                            {active.status === 'CLOSED' && (
                                <div className="mt-5 border-t pt-4">
                                    <p className="text-muted-foreground text-xs">
                                        Ditutup {active.closedAt ? formatDate(active.closedAt) : '—'}
                                        {active.closedBy ? ` oleh ${active.closedBy}` : ''} · snapshot versi {active.snapshotVersion}.
                                    </p>
                                    <Button size="sm" variant="outline" className="mt-2" onClick={() => setBukaUlangTarget(active)}>
                                        <LockOpen className="mr-1 h-3.5 w-3.5" /> Ajukan Buka Ulang
                                    </Button>
                                </div>
                            )}
                        </Panel>

                        {/* ---------------------------------------- pemeriksaan penutupan */}
                        <Panel
                            title="Pemeriksaan Penutupan"
                            description={
                                active.status === 'DRAFT'
                                    ? 'Berlaku setelah periode dibuka'
                                    : blocker.length > 0
                                      ? `${blocker.length} hal harus diselesaikan dulu`
                                      : 'Siap ditutup'
                            }
                        >
                            <ul className="space-y-2.5">
                                {active.checks.map((c) => {
                                    const { icon: Icon, cls } = IKON_PERIKSA[c.status];
                                    return (
                                        <li key={c.key} className="flex gap-2.5">
                                            <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', cls)} />
                                            <div className="min-w-0">
                                                <p className="text-sm leading-snug">{c.label}</p>
                                                <p className="text-2xs text-muted-foreground">{c.detail}</p>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>

                            {(active.status === 'OPEN' || active.status === 'REOPENED') && (
                                <Button size="sm" className="mt-4 w-full" onClick={bukaDialogTutup}>
                                    <Lock className="mr-1 h-3.5 w-3.5" /> Tutup Periode
                                </Button>
                            )}
                        </Panel>
                    </div>
                )}

                {/* ------------------------------------------------ rincian snapshot */}
                {active?.snapshot != null && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Persediaan" description={`Snapshot versi ${active.snapshot.version}`}>
                            <dl className="space-y-1 text-xs">
                                {[
                                    ['Persediaan awal', active.snapshot.openingInventory],
                                    ['Pembelian', active.snapshot.purchase],
                                    ['Retur pembelian', -active.snapshot.purchaseReturn],
                                    ['HPP penjualan', -active.snapshot.salesCost],
                                    ['HPP retur penjualan', active.snapshot.salesReturnCost],
                                    ['Penyesuaian', active.snapshot.adjustment],
                                ].map(([label, value]) => (
                                    <div key={label as string} className="flex justify-between">
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                    </div>
                                ))}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Persediaan akhir</dt>
                                    <dd className="tabular font-mono">{formatRupiah(active.snapshot.endingInventory)}</dd>
                                </div>
                            </dl>
                        </Panel>

                        <Panel title="Kas" description="Saldo awal tidak menghitung ulang uang laci yang bergulir antar-shift">
                            <dl className="space-y-1 text-xs">
                                {[
                                    ['Kas awal', active.snapshot.openingCash],
                                    ['Penjualan tunai', active.snapshot.cashSales],
                                    ['Kas masuk lain', active.snapshot.cashIn],
                                    ['Kas keluar', -active.snapshot.cashOut],
                                    ['Refund', -active.snapshot.refund],
                                ].map(([label, value]) => (
                                    <div key={label as string} className="flex justify-between">
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                    </div>
                                ))}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Kas akhir</dt>
                                    <dd className="tabular font-mono">{formatRupiah(active.snapshot.endingCash)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Selisih kas tercatat</dt>
                                    <dd className="tabular font-mono">{formatRupiah(active.snapshot.cashDifference)}</dd>
                                </div>
                            </dl>
                        </Panel>
                    </div>
                )}

                {/* -------------------------------------------------- selisih kas */}
                <Panel
                    title="Selisih Kas"
                    description={
                        selisihTerbuka.length > 0
                            ? `${selisihTerbuka.length} belum diselesaikan`
                            : 'Lahir otomatis saat shift ditutup dengan uang fisik berbeda dari buku kas'
                    }
                    bodyClassName="p-0"
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-40">Shift</TableHead>
                                    <TableHead>Kasir</TableHead>
                                    <TableHead className="w-28 text-right">Seharusnya</TableHead>
                                    <TableHead className="w-28 text-right">Fisik</TableHead>
                                    <TableHead className="w-28 text-right">Selisih</TableHead>
                                    <TableHead className="w-44">Penyelesaian</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {differences.length === 0 && (
                                    <EmptyRow colSpan={6} icon={<Scale />} message="Belum ada selisih kas tercatat." />
                                )}

                                {differences.map((d) => (
                                    <TableRow key={d.id}>
                                        <TableCell className="tabular font-mono text-xs">{d.shiftNumber ?? '—'}</TableCell>
                                        <TableCell className="text-xs">{d.cashier ?? '—'}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(d.expected)}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(d.actual)}</TableCell>
                                        <TableCell
                                            className={cn(
                                                'tabular text-right font-mono text-xs font-medium',
                                                d.difference < 0 && 'text-destructive',
                                            )}
                                        >
                                            {formatRupiah(d.difference)}
                                        </TableCell>
                                        <TableCell>
                                            {d.status === 'RESOLVED' ? (
                                                <span className="text-2xs text-muted-foreground">
                                                    {LABEL_RESOLUSI[d.resolutionType ?? ''] ?? d.resolutionType}
                                                    {d.resolvedBy ? ` · ${d.resolvedBy}` : ''}
                                                </span>
                                            ) : d.isMine ? (
                                                <span className="text-2xs text-muted-foreground">Menunggu atasan</span>
                                            ) : (
                                                <Button variant="outline" size="sm" className="h-7" onClick={() => bukaDialogSelisih(d)}>
                                                    Selesaikan
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Panel>

                {/* ------------------------------------------ permintaan buka ulang */}
                {requests.length > 0 && (
                    <Panel
                        title="Permintaan Buka Ulang"
                        description={
                            permintaanMenggantung.length > 0
                                ? `${permintaanMenggantung.length} menunggu persetujuan pemilik`
                                : 'Semua permintaan sudah ditinjau'
                        }
                        bodyClassName="p-0"
                    >
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-32">Periode</TableHead>
                                        <TableHead>Alasan</TableHead>
                                        <TableHead className="w-36">Pengaju</TableHead>
                                        <TableHead className="w-44 text-right">Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {requests.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="tabular font-mono text-xs">{r.periodCode}</TableCell>
                                            <TableCell className="text-xs whitespace-pre-line">{r.reason}</TableCell>
                                            <TableCell className="text-2xs text-muted-foreground">
                                                {r.requestedBy ?? '—'}
                                                {r.requestedAt && <span className="block">{r.requestedAt}</span>}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {r.status === 'PENDING' && canApprove ? (
                                                    <span className="flex justify-end gap-1">
                                                        <Button
                                                            size="sm"
                                                            className="h-7"
                                                            onClick={() => tinjau(r.id, 'setujui')}
                                                            disabled={tinjauForm.processing}
                                                        >
                                                            Setujui
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-7"
                                                            onClick={() => tinjau(r.id, 'tolak')}
                                                            disabled={tinjauForm.processing}
                                                        >
                                                            Tolak
                                                        </Button>
                                                    </span>
                                                ) : (
                                                    <span className="flex items-center justify-end gap-1">
                                                        <Chip tone={r.status === 'APPROVED' ? 'accent' : 'neutral'}>
                                                            {r.status === 'PENDING'
                                                                ? 'Menunggu'
                                                                : r.status === 'APPROVED'
                                                                  ? 'Disetujui'
                                                                  : 'Ditolak'}
                                                        </Chip>
                                                        {r.approvalType === 'SELF_APPROVED' && <Chip tone="warning">Sendiri</Chip>}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {errors?.request && <p className="text-2xs text-destructive px-4 py-2 font-medium">{errors.request}</p>}
                    </Panel>
                )}

                {/* -------------------------------------------------- riwayat */}
                <Panel title="Riwayat Periode" description={`${periods.length} periode`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-40">Periode</TableHead>
                                    <TableHead className="w-44">Rentang</TableHead>
                                    <TableHead className="w-28">Lokasi</TableHead>
                                    <TableHead className="w-24">Costing</TableHead>
                                    <TableHead className="w-28 text-right">Penjualan</TableHead>
                                    <TableHead className="w-28 text-right">Laba Bersih</TableHead>
                                    <TableHead className="w-32">Ditutup</TableHead>
                                    <TableHead className="w-36 text-right">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {periods.length === 0 && (
                                    <EmptyRow colSpan={8} icon={<CalendarRange />} message="Belum ada periode pembukuan." />
                                )}

                                {periods.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <p className="font-mono text-xs font-medium">{p.code}</p>
                                            <p className="text-2xs text-muted-foreground">{p.name}</p>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {formatDate(p.startDate)} – {formatDate(p.endDate)}
                                        </TableCell>
                                        <TableCell className="text-2xs">{p.branch ?? 'Semua'}</TableCell>
                                        <TableCell>
                                            <span className="bg-muted text-2xs rounded px-1.5 py-0.5 font-mono">{p.costingMethod}</span>
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">
                                            {p.netSales === null ? '—' : formatRupiah(p.netSales)}
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs font-medium">
                                            {p.netProfit === null ? '—' : formatRupiah(p.netProfit)}
                                        </TableCell>
                                        <TableCell className="text-2xs text-muted-foreground">
                                            {p.closedAt ? (
                                                <>
                                                    {formatDate(p.closedAt)}
                                                    <span className="block">{p.closedBy}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <span className="flex items-center justify-end gap-1">
                                                <Chip tone={TONE_STATUS[p.status]}>{LABEL_STATUS[p.status]}</Chip>
                                                {p.status === 'CLOSED' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground hover:text-foreground h-6 w-6"
                                                        onClick={() => setBukaUlangTarget(p)}
                                                        aria-label="Ajukan buka ulang"
                                                        title="Ajukan buka ulang"
                                                    >
                                                        <LockOpen className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Panel>
            </div>

            {/* ==================================================== dialog periode baru */}
            <Dialog open={buatTerbuka} onOpenChange={setBuatTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Periode baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Cukup tentukan rentang tanggalnya; kode dan nama dibuat sistem. Tanggal yang disarankan
                            menyambung periode terakhir lokasi tersebut dan tidak boleh bertindih dengannya.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Berlaku untuk" error={buatForm.errors.branch_id}>
                            <NativeSelect
                                value={buatForm.data.branch_id}
                                onChange={gantiLokasiPeriode}
                                placeholder="Seluruh lokasi"
                                options={branches.map((b) => ({ value: b.id, label: b.name }))}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Mulai" required error={buatForm.errors.start_date}>
                                <Input
                                    type="date"
                                    value={buatForm.data.start_date}
                                    onChange={(e) => buatForm.setData('start_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                            <Field label="Selesai" required error={buatForm.errors.end_date}>
                                <Input
                                    type="date"
                                    value={buatForm.data.end_date}
                                    onChange={(e) => buatForm.setData('end_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <div className="bg-muted/40 rounded-md px-3 py-2">
                            <p className="label-eyebrow">Dibuat otomatis</p>
                            <p className="mt-1 flex items-baseline gap-2">
                                <span className="font-mono text-sm font-semibold">{pratinjauKode}</span>
                                <span className="text-muted-foreground text-xs">{pratinjauNama}</span>
                            </p>
                            <p className="text-2xs text-muted-foreground mt-1 leading-relaxed">
                                Mengikuti bulan tanggal mulai. Kode ini ikut tercetak pada nomor setiap dokumen periode
                                ini, jadi sistem yang menyusunnya; bila kodenya sudah terpakai, akhiran urut ditambahkan.
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBuatTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanPeriode} disabled={buatForm.processing}>
                            {buatForm.processing ? 'Menyimpan...' : 'Simpan sebagai draf'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ====================================================== dialog saldo awal */}
            <Dialog open={openingTerbuka} onOpenChange={setOpeningTerbuka}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="text-base">Saldo awal {active?.code}</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Hanya sekali, dan hanya selama periode masih draf. Harga diisi per satuan yang dipilih — sistem yang
                            membaginya ke satuan dasar.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="max-h-[60vh] space-y-4 overflow-y-auto">
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="label-eyebrow">Stok awal</span>
                                <Button type="button" variant="outline" size="sm" className="h-7" onClick={() => setRows((p) => [...p, barisBaru()])}>
                                    <Plus className="h-3.5 w-3.5" /> Tambah baris
                                </Button>
                            </div>

                            {rows.length === 0 && (
                                <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-4 text-center">
                                    Belum ada baris. Boleh dikosongkan bila memang belum ada stok.
                                </p>
                            )}

                            {rows.map((row) => {
                                const produk = products.find((p) => p.id === Number(row.product_id)) ?? null;
                                const satuan = produk?.uoms.find((u) => u.id === Number(row.uom_id)) ?? null;
                                const qtyBase = satuan && row.qty !== '' ? Number(row.qty) * satuan.factor : 0;

                                return (
                                    <div key={row.key} className="rounded-md border p-2">
                                        <div className="flex items-end gap-2">
                                            <div className="min-w-0 flex-1">
                                                <NativeSelect
                                                    value={row.product_id}
                                                    onChange={(v) => pilihProduk(row.key, v)}
                                                    placeholder="Pilih produk"
                                                    options={products.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }))}
                                                />
                                            </div>
                                            <div className="w-24">
                                                <NativeSelect
                                                    value={row.uom_id}
                                                    onChange={(v) => ubahBaris(row.key, 'uom_id', v)}
                                                    placeholder={produk ? 'Satuan' : '—'}
                                                    disabled={!produk}
                                                    options={(produk?.uoms ?? []).map((u) => ({ value: u.id, label: u.name }))}
                                                />
                                            </div>
                                            <div className="w-20">
                                                <Input
                                                    type="number"
                                                    inputMode="decimal"
                                                    step={produk?.isWeighted ? 0.01 : 1}
                                                    min={0}
                                                    value={row.qty}
                                                    onChange={(e) => ubahBaris(row.key, 'qty', e.target.value)}
                                                    placeholder="Qty"
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                            <div className="w-28">
                                                <Input
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={0}
                                                    value={row.unit_cost}
                                                    onChange={(e) => ubahBaris(row.key, 'unit_cost', e.target.value)}
                                                    placeholder="Harga beli"
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground hover:text-destructive h-8 w-8"
                                                onClick={() => setRows((p) => p.filter((r) => r.key !== row.key))}
                                                aria-label="Hapus baris"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                        {produk && satuan && (
                                            <p className="text-2xs text-muted-foreground mt-1">
                                                = {qtyBase.toLocaleString('id-ID')} satuan dasar · nilai{' '}
                                                {formatRupiah(Number(row.qty || 0) * Number(row.unit_cost || 0))}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}

                            {openingForm.errors.lines && (
                                <p className="text-2xs text-destructive font-medium">{openingForm.errors.lines}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-3 gap-2 border-t pt-3">
                            <Field label="Kas awal" error={openingForm.errors.opening_cash}>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    value={openingForm.data.opening_cash}
                                    onChange={(e) => openingForm.setData('opening_cash', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>
                            <Field label="Piutang awal" error={openingForm.errors.opening_receivable}>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    value={openingForm.data.opening_receivable}
                                    onChange={(e) => openingForm.setData('opening_receivable', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>
                            <Field label="Utang awal" error={openingForm.errors.opening_payable}>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    value={openingForm.data.opening_payable}
                                    onChange={(e) => openingForm.setData('opening_payable', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>
                        </div>

                        <Field label="Catatan" error={openingForm.errors.note}>
                            <Input
                                value={openingForm.data.note}
                                onChange={(e) => openingForm.setData('note', e.target.value)}
                                placeholder="Dasar penilaian stok awal, misalnya harga beli terakhir"
                                className="h-8"
                            />
                        </Field>

                        {errors?.period && <p className="text-2xs text-destructive font-medium">{errors.period}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setOpeningTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanOpening} disabled={openingForm.processing}>
                            {openingForm.processing ? 'Menyimpan...' : 'Simpan saldo awal'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================== dialog tutup periode */}
            <Dialog open={tutupTerbuka} onOpenChange={setTutupTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <CalendarRange className="h-4 w-4" />
                            Tutup {active?.code}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            Setelah ditutup, seluruh transaksi periode ini terkunci. Perubahan hanya mungkin lewat buka ulang yang
                            memerlukan alasan dan persetujuan pemilik.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <ul className="space-y-2 rounded-md border p-3">
                            {(active?.checks ?? []).map((c) => {
                                const ditembus = tutupForm.data.overrides.includes(c.key);
                                const { icon: Icon, cls } = IKON_PERIKSA[ditembus && c.status === 'error' ? 'warn' : c.status];
                                return (
                                    <li key={c.key} className="flex items-start gap-2.5 text-sm">
                                        <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', cls)} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block leading-snug">{c.label}</span>
                                            <span className="text-2xs text-muted-foreground">{c.detail}</span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>

                        {bisaDitembus.length > 0 && (
                            <div className="space-y-2 rounded-md border border-dashed p-3">
                                <p className="text-2xs text-muted-foreground">
                                    Sebagian penghalang boleh ditembus persetujuan pemilik. Persetujuannya dicatat permanen beserta
                                    alasannya.
                                </p>
                                {bisaDitembus.map((c) => (
                                    <label key={c.key} className="flex items-start gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5"
                                            checked={tutupForm.data.overrides.includes(c.key)}
                                            onChange={() => ubahOverride(c.key)}
                                        />
                                        <span>Tutup meski {c.label.toLowerCase()}</span>
                                    </label>
                                ))}

                                {tutupForm.data.overrides.length > 0 && (
                                    <Field label="Alasan menembus" required error={tutupForm.errors.override_reason}>
                                        <Input
                                            value={tutupForm.data.override_reason}
                                            onChange={(e) => tutupForm.setData('override_reason', e.target.value)}
                                            placeholder="Alasan yang akan tersimpan di jejak audit"
                                            className="h-8"
                                        />
                                    </Field>
                                )}
                            </div>
                        )}

                        {blocker.length > 0 && (
                            <p className="bg-destructive/10 text-destructive flex items-start gap-2 rounded-md px-3 py-2 text-xs">
                                <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                Penutupan diblokir sampai {blocker.length} hal di atas diselesaikan.
                            </p>
                        )}
                        {blocker.length === 0 && peringatan.length > 0 && (
                            <p className="bg-warning/10 flex items-start gap-2 rounded-md px-3 py-2 text-xs">
                                <TriangleAlert className="text-warning mt-0.5 h-3.5 w-3.5 shrink-0" />
                                Ada {peringatan.length} peringatan. Penutupan tetap bisa dilanjutkan.
                            </p>
                        )}

                        {errors?.period && <p className="text-2xs text-destructive font-medium">{errors.period}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setTutupTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={tutupPeriode} disabled={tutupForm.processing || blocker.length > 0}>
                            <Lock className="mr-1 h-3.5 w-3.5" />
                            {tutupForm.processing ? 'Menutup...' : 'Jalankan Penutupan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ==================================================== dialog buka ulang */}
            <Dialog open={!!bukaUlangTarget} onOpenChange={(open) => !open && setBukaUlangTarget(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="text-base">Ajukan buka ulang {bukaUlangTarget?.code}</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Permintaan ini menunggu persetujuan pemilik. Setelah disetujui, snapshot lama ditandai diganti dan periode
                            wajib ditutup ulang.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        {(active?.affected.length ?? 0) > 0 && bukaUlangTarget?.id === active?.id && (
                            <p className="bg-warning/10 rounded-md px-3 py-2 text-xs">
                                Periode berikut ikut terdampak dan harus ditutup ulang juga:{' '}
                                {active?.affected.map((a) => a.code).join(', ')}.
                            </p>
                        )}

                        <Field label="Alasan" required error={bukaUlangForm.errors.reason}>
                            <Input
                                value={bukaUlangForm.data.reason}
                                onChange={(e) => bukaUlangForm.setData('reason', e.target.value)}
                                placeholder="Contoh: ada nota Agustus yang belum sempat divoid"
                                className="h-8"
                            />
                        </Field>

                        {errors?.period && <p className="text-2xs text-destructive font-medium">{errors.period}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBukaUlangTarget(null)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={ajukanBukaUlang} disabled={bukaUlangForm.processing}>
                            {bukaUlangForm.processing ? 'Mengirim...' : 'Ajukan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================ dialog selesaikan selisih */}
            <Dialog open={!!selisihTarget} onOpenChange={(open) => !open && setSelisihTarget(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Selesaikan selisih {selisihTarget?.shiftNumber}</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Selisih {selisihTarget && formatRupiah(selisihTarget.difference)}. Apa pun pilihannya, buku kas disamakan
                            dengan uang fisik lewat satu baris kas bertanggal hari ini.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Cara penyelesaian" required error={selisihForm.errors.resolution_type}>
                            <NativeSelect
                                value={selisihForm.data.resolution_type}
                                onChange={(v) => selisihForm.setData('resolution_type', v)}
                                options={pilihanResolusi}
                            />
                        </Field>

                        <Field label="Alasan" required error={selisihForm.errors.note}>
                            <Input
                                value={selisihForm.data.note}
                                onChange={(e) => selisihForm.setData('note', e.target.value)}
                                placeholder="Penjelasan yang tersimpan permanen"
                                className="h-8"
                            />
                        </Field>

                        {errors?.difference && <p className="text-2xs text-destructive font-medium">{errors.difference}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setSelisihTarget(null)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={selesaikanSelisih} disabled={selisihForm.processing}>
                            {selisihForm.processing ? 'Menyimpan...' : 'Selesaikan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
