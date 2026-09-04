import { Chip, Field, FlashMessage, NativeSelect, ServerPagination, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Ban, Wallet } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Banking', href: '#' },
    { title: 'Kas & Biaya', href: '/biaya' },
];

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
};

interface Kategori {
    id: number;
    name: string;
}

interface Biaya {
    id: number;
    number: string;
    date: string;
    category: string;
    description: string;
    amount: number;
    paymentType: string;
    status: 'POSTED' | 'VOID';
    voidReason: string | null;
    author: string | null;
}

export default function BiayaIndex() {
    const { expenses, categories, summary, today } = usePage().props as unknown as {
        expenses: Paginated<Biaya>;
        categories: Kategori[];
        summary: { countBulanIni: number; totalBulanIni: number; totalTunaiBulanIni: number };
        today: string;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [detail, setDetail] = useState<Biaya | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);

    const form = useForm({
        expense_category_id: '' as string | number,
        transaction_date: today,
        description: '',
        amount: '',
        payment_type: 'CASH',
    });

    const voidForm = useForm({ void_reason: '' });

    function bukaDialog() {
        form.setData({ expense_category_id: '', transaction_date: today, description: '', amount: '', payment_type: 'CASH' });
        form.clearErrors();
        setDialogTerbuka(true);
    }

    function simpan() {
        form.post('/biaya', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    function bukaBatal() {
        voidForm.setData('void_reason', '');
        voidForm.clearErrors();
        setBatalTerbuka(true);
    }

    function batalkan() {
        if (!detail) return;
        voidForm.post(`/biaya/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    const kartu = [
        { label: 'Biaya bulan ini', value: String(summary.countBulanIni) },
        { label: 'Total bulan ini', value: formatRupiah(summary.totalBulanIni) },
        { label: 'Total tunai bulan ini', value: formatRupiah(summary.totalTunaiBulanIni) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kas & Biaya" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Kas & Biaya"
                    subtitle="Biaya operasional — tidak memengaruhi HPP, murni mengurangi laba bersih."
                    action={
                        <Button size="sm" className="h-8" onClick={bukaDialog}>
                            <Wallet className="h-3.5 w-3.5" /> Biaya baru
                        </Button>
                    }
                />

                <FlashMessage />

                <div className="bg-muted/30 text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-2">
                    Biaya tunai sudah mengurangi buku kas. Penjualan tunai belum menulis ke buku yang sama (menyusul bersama Shift Kasir), jadi
                    saldo kas belum bisa dihitung utuh di layar ini.
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    {kartu.map((k) => (
                        <div key={k.label} className="bg-card rounded-lg border px-4 py-3">
                            <p className="label-eyebrow">{k.label}</p>
                            <p className="tabular mt-1 font-mono text-xl font-semibold">{k.value}</p>
                        </div>
                    ))}
                </div>

                <Panel title="Daftar Biaya" description={`${expenses.total} biaya tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead>Keterangan</TableHead>
                                    <TableHead className="w-24">Metode</TableHead>
                                    <TableHead className="w-28 text-right">Nilai</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {expenses.data.length === 0 && <EmptyRow colSpan={7} icon={<Wallet />} message="Belum ada biaya tercatat." />}

                                {expenses.data.map((e) => (
                                    <TableRow
                                        key={e.id}
                                        onClick={() => setDetail(e)}
                                        className={cn('cursor-pointer', e.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{e.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(e.date)}</TableCell>
                                        <TableCell className="text-xs">{e.category}</TableCell>
                                        <TableCell className="max-w-64 truncate text-xs">{e.description}</TableCell>
                                        <TableCell className="text-2xs">{LABEL_METODE[e.paymentType] ?? e.paymentType}</TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(e.amount)}</TableCell>
                                        <TableCell>
                                            {e.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={expenses} />
                </Panel>
            </div>

            {/* ===================================================== dialog biaya baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Biaya baru</DialogTitle>
                        <DialogDescription className="text-2xs">Langsung tercatat dan terposting — bukan draf.</DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <Field label="Kategori" required error={form.errors.expense_category_id}>
                            <NativeSelect
                                value={form.data.expense_category_id}
                                onChange={(v) => form.setData('expense_category_id', v)}
                                placeholder="Pilih kategori"
                                options={categories.map((c) => ({ value: c.id, label: c.name }))}
                            />
                        </Field>

                        <Field label="Keterangan" required error={form.errors.description}>
                            <Input
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Contoh: token listrik toko bulan ini"
                                className="h-8"
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Tanggal" required error={form.errors.transaction_date}>
                                <Input
                                    type="date"
                                    value={form.data.transaction_date}
                                    onChange={(e) => form.setData('transaction_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>

                            <Field label="Nilai" required error={form.errors.amount}>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    value={form.data.amount}
                                    onChange={(e) => form.setData('amount', e.target.value)}
                                    className="h-8 text-right font-mono"
                                />
                            </Field>
                        </div>

                        <Field label="Metode bayar" required error={form.errors.payment_type}>
                            <NativeSelect
                                value={form.data.payment_type}
                                onChange={(v) => form.setData('payment_type', v)}
                                options={Object.entries(LABEL_METODE).map(([value, label]) => ({ value, label }))}
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan biaya'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ======================================================== dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Wallet className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · {detail.category}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-2 text-xs">
                            <p>{detail.description}</p>
                            <div className="flex justify-between border-t pt-2">
                                <span className="text-muted-foreground">Metode</span>
                                <span>{LABEL_METODE[detail.paymentType] ?? detail.paymentType}</span>
                            </div>
                            <div className="flex justify-between font-medium">
                                <span>Nilai</span>
                                <span className="tabular font-mono">{formatRupiah(detail.amount)}</span>
                            </div>
                            {detail.status === 'VOID' && detail.voidReason && (
                                <p className="bg-warning/10 text-2xs rounded-md px-2.5 py-1.5">
                                    <span className="font-medium">Alasan pembatalan:</span> {detail.voidReason}
                                </p>
                            )}
                            {detail.author && <p className="text-muted-foreground text-2xs">oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {detail?.status === 'POSTED' && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Biaya
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================== dialog batal */}
            <Dialog open={batalTerbuka} onOpenChange={setBatalTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="text-base">Batalkan {detail?.number}?</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Bila bermetode tunai, kasnya dikembalikan pada tanggal pembatalan ini. Biaya tetap tersimpan dan nomornya tidak dipakai
                            ulang — pembatalan tidak dapat diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <label className="label-eyebrow" htmlFor="alasan-batal-biaya">
                            Alasan pembatalan
                        </label>
                        <textarea
                            id="alasan-batal-biaya"
                            autoFocus
                            rows={3}
                            value={voidForm.data.void_reason}
                            onChange={(e) => voidForm.setData('void_reason', e.target.value)}
                            placeholder="Contoh: salah kategori, dicatat ulang dengan benar."
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-2 py-1.5 text-sm shadow-xs focus:ring-2 focus:ring-offset-2 focus:outline-none"
                        />
                        {voidForm.errors.void_reason && <p className="text-2xs text-destructive font-medium">{voidForm.errors.void_reason}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={voidForm.processing}>
                            {voidForm.processing ? 'Membatalkan...' : 'Ya, batalkan biaya'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
