import { Chip, Field, FlashMessage, NativeSelect, ServerPagination, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Ban, ReceiptText } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pembelian', href: '#' },
    { title: 'Faktur Pemasok', href: '/pembelian' },
];

interface Pemasok {
    id: number;
    name: string;
}

interface Pajak {
    id: number;
    name: string;
    rate: number;
}

interface BarisFaktur {
    name: string;
    qty: number;
    uom: string;
    unitCost: number;
    lineTotal: number;
}

interface Faktur {
    id: number;
    number: string;
    date: string;
    supplier: string | null;
    status: 'POSTED' | 'VOID';
    subtotal: number;
    taxTotal: number;
    total: number;
    author: string | null;
    receipts: string[];
    items: BarisFaktur[];
}

interface PenerimaanItem {
    id: number;
    name: string;
    sku: string;
    qty: number;
    uom: string;
    unitCost: number;
    costSource: string;
}

interface PenerimaanTerbuka {
    id: number;
    number: string;
    date: string;
    supplierId: number | null;
    supplier: string | null;
    totalCost: number;
    items: PenerimaanItem[];
}

interface Override {
    qty: string;
    unitCost: string;
}

export default function FakturIndex() {
    const { purchases, openReceipts, suppliers, taxes, today, errors } = usePage().props as unknown as {
        purchases: Paginated<Faktur>;
        openReceipts: PenerimaanTerbuka[];
        suppliers: Pemasok[];
        taxes: Pajak[];
        today: string;
        errors: Record<string, string>;
    };

    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [detail, setDetail] = useState<Faktur | null>(null);
    const [batalTerbuka, setBatalTerbuka] = useState(false);
    const [receiptIds, setReceiptIds] = useState<number[]>([]);
    const [overrides, setOverrides] = useState<Record<number, Override>>({});

    const form = useForm({
        supplier_id: '' as string | number,
        transaction_date: today,
        due_date: '',
        tax_id: '' as string | number,
        receipt_ids: [] as number[],
        lines: [] as { receipt_item_id: number; qty: number; unit_cost: number }[],
    });

    const voidForm = useForm({});

    function bukaDialog() {
        form.setData({ supplier_id: '', transaction_date: today, due_date: '', tax_id: '', receipt_ids: [], lines: [] });
        form.clearErrors();
        setReceiptIds([]);
        setOverrides({});
        setDialogTerbuka(true);
    }

    function pilihPemasok(v: string) {
        form.setData('supplier_id', v);
        setReceiptIds([]);
    }

    const penerimaanTersedia = useMemo(
        () =>
            form.data.supplier_id === ''
                ? []
                : openReceipts.filter((r) => r.supplierId === null || r.supplierId === Number(form.data.supplier_id)),
        [openReceipts, form.data.supplier_id],
    );

    function toggleReceipt(receipt: PenerimaanTerbuka) {
        setReceiptIds((prev) => {
            if (prev.includes(receipt.id)) {
                return prev.filter((id) => id !== receipt.id);
            }

            setOverrides((prevOv) => {
                const next = { ...prevOv };
                receipt.items.forEach((item) => {
                    if (!next[item.id]) {
                        next[item.id] = { qty: String(item.qty), unitCost: String(item.unitCost) };
                    }
                });
                return next;
            });

            return [...prev, receipt.id];
        });
    }

    function ubahOverride(itemId: number, field: keyof Override, value: string) {
        setOverrides((prev) => ({ ...prev, [itemId]: { ...prev[itemId], [field]: value } }));
    }

    const barisTerpilih = useMemo(
        () =>
            receiptIds.flatMap((id) => {
                const r = penerimaanTersedia.find((x) => x.id === id) ?? openReceipts.find((x) => x.id === id);
                return (r?.items ?? []).map((item) => ({ ...item, receiptNumber: r!.number }));
            }),
        [receiptIds, penerimaanTersedia, openReceipts],
    );

    const subtotal = barisTerpilih.reduce((s, item) => {
        const ov = overrides[item.id];
        return s + Number(ov?.qty ?? item.qty) * Number(ov?.unitCost ?? item.unitCost);
    }, 0);
    const pajakTerpilih = taxes.find((t) => t.id === Number(form.data.tax_id)) ?? null;
    const taxTotal = pajakTerpilih ? (subtotal * pajakTerpilih.rate) / 100 : 0;
    const total = subtotal + taxTotal;

    function simpan() {
        form.transform((data) => ({
            ...data,
            receipt_ids: receiptIds,
            lines: barisTerpilih.map((item) => ({
                receipt_item_id: item.id,
                qty: Number(overrides[item.id]?.qty ?? item.qty),
                unit_cost: Number(overrides[item.id]?.unitCost ?? item.unitCost),
            })),
        }));
        form.post('/pembelian', {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        });
    }

    function bukaBatal() {
        voidForm.clearErrors();
        setBatalTerbuka(true);
    }

    function batalkan() {
        if (!detail) return;
        voidForm.post(`/pembelian/${detail.id}/batal`, {
            preserveScroll: true,
            onSuccess: () => {
                setBatalTerbuka(false);
                setDetail(null);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Faktur Pemasok" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Faktur Pemasok"
                    subtitle="Mengakui utang dan pajak dari barang yang sudah diterima. Faktur tidak menyentuh stok."
                    action={
                        <Button size="sm" className="h-8" onClick={bukaDialog}>
                            <ReceiptText className="h-3.5 w-3.5" /> Faktur baru
                        </Button>
                    }
                />

                <FlashMessage />

                <Panel title="Daftar Faktur" description={`${purchases.total} faktur tercatat`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-44">Nomor</TableHead>
                                    <TableHead className="w-24">Tanggal</TableHead>
                                    <TableHead>Pemasok</TableHead>
                                    <TableHead>Penerimaan</TableHead>
                                    <TableHead className="w-28 text-right">Total</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {purchases.data.length === 0 && (
                                    <EmptyRow colSpan={6} icon={<ReceiptText />} message="Belum ada faktur pemasok." />
                                )}

                                {purchases.data.map((p) => (
                                    <TableRow
                                        key={p.id}
                                        onClick={() => setDetail(p)}
                                        className={cn('cursor-pointer', p.status === 'VOID' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="tabular font-mono text-xs font-semibold">{p.number}</TableCell>
                                        <TableCell className="text-muted-foreground tabular text-xs">{formatDate(p.date)}</TableCell>
                                        <TableCell className="text-xs">{p.supplier ?? '—'}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {p.receipts.map((r) => (
                                                    <Chip key={r}>{r}</Chip>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(p.total)}</TableCell>
                                        <TableCell>
                                            {p.status === 'VOID' ? <Chip tone="warning">Dibatalkan</Chip> : <Chip tone="accent">Diposting</Chip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={purchases} />
                </Panel>
            </div>

            {/* ===================================================== dialog faktur baru */}
            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Faktur baru</DialogTitle>
                        <DialogDescription className="text-2xs">
                            Pilih pemasok, lalu centang penerimaan yang ditagih. Harga boleh disunting sesuai faktur yang sebenarnya diterima.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Pemasok" required error={form.errors.supplier_id}>
                                <NativeSelect
                                    value={form.data.supplier_id}
                                    onChange={pilihPemasok}
                                    placeholder="Pilih pemasok"
                                    options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                                />
                            </Field>

                            <Field label="Tanggal faktur" required error={form.errors.transaction_date}>
                                <Input
                                    type="date"
                                    value={form.data.transaction_date}
                                    onChange={(e) => form.setData('transaction_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>

                            <Field label="Jatuh tempo" error={form.errors.due_date}>
                                <Input
                                    type="date"
                                    value={form.data.due_date}
                                    onChange={(e) => form.setData('due_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <Field label="Pajak" error={form.errors.tax_id} hint="Opsional — dihitung dari subtotal baris terpilih.">
                            <NativeSelect
                                value={form.data.tax_id}
                                onChange={(v) => form.setData('tax_id', v)}
                                placeholder="Tanpa pajak"
                                options={taxes.map((t) => ({ value: t.id, label: `${t.name} (${t.rate}%)` }))}
                            />
                        </Field>

                        <div className="space-y-2">
                            <span className="label-eyebrow">Penerimaan belum ditagih</span>

                            {form.data.supplier_id === '' && (
                                <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-4 text-center">
                                    Pilih pemasok dulu.
                                </p>
                            )}

                            {form.data.supplier_id !== '' && penerimaanTersedia.length === 0 && (
                                <p className="text-2xs text-muted-foreground rounded-md border border-dashed px-3 py-4 text-center">
                                    Tidak ada penerimaan yang menunggu faktur untuk pemasok ini.
                                </p>
                            )}

                            <div className="max-h-40 space-y-1.5 overflow-y-auto">
                                {penerimaanTersedia.map((r) => (
                                    <label
                                        key={r.id}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2 rounded-md border p-2 text-xs transition-colors',
                                            receiptIds.includes(r.id) ? 'border-primary bg-primary/5' : 'hover:bg-accent',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={receiptIds.includes(r.id)}
                                            onChange={() => toggleReceipt(r)}
                                            className="h-3.5 w-3.5"
                                        />
                                        <span className="flex-1">
                                            <span className="font-mono font-semibold">{r.number}</span>
                                            <span className="text-muted-foreground ml-1.5">
                                                {formatDate(r.date)} · {r.items.length} baris
                                            </span>
                                        </span>
                                        <span className="tabular font-mono">{formatRupiah(r.totalCost)}</span>
                                    </label>
                                ))}
                            </div>

                            {form.errors.receipt_ids && <p className="text-2xs text-destructive font-medium">{form.errors.receipt_ids}</p>}
                        </div>

                        {barisTerpilih.length > 0 && (
                            <div className="space-y-2">
                                <span className="label-eyebrow">Baris ditagih</span>
                                <div className="max-h-56 space-y-1.5 overflow-y-auto">
                                    {barisTerpilih.map((item) => (
                                        <div key={item.id} className="flex items-end gap-2 rounded-md border p-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-xs">{item.name}</p>
                                                <p className="text-2xs text-muted-foreground font-mono">
                                                    {item.receiptNumber} · maks {formatQty(item.qty)} {item.uom}
                                                </p>
                                            </div>
                                            <div className="w-20">
                                                <Input
                                                    type="number"
                                                    inputMode="decimal"
                                                    min={0}
                                                    max={item.qty}
                                                    value={overrides[item.id]?.qty ?? String(item.qty)}
                                                    onChange={(e) => ubahOverride(item.id, 'qty', e.target.value)}
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                            <div className="w-28">
                                                <Input
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={0}
                                                    value={overrides[item.id]?.unitCost ?? String(item.unitCost)}
                                                    onChange={(e) => ubahOverride(item.id, 'unitCost', e.target.value)}
                                                    className="h-8 text-right font-mono"
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {form.errors.lines && <p className="text-2xs text-destructive font-medium">{form.errors.lines}</p>}
                            </div>
                        )}

                        {barisTerpilih.length > 0 && (
                            <dl className="bg-muted/50 space-y-1 rounded-md px-3 py-2 text-xs">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Subtotal</dt>
                                    <dd className="tabular font-mono">{formatRupiah(subtotal)}</dd>
                                </div>
                                {pajakTerpilih && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">{pajakTerpilih.name}</dt>
                                        <dd className="tabular font-mono">{formatRupiah(taxTotal)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Total</dt>
                                    <dd className="tabular font-mono">{formatRupiah(total)}</dd>
                                </div>
                            </dl>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing || receiptIds.length === 0}>
                            {form.processing ? 'Menyimpan...' : 'Simpan faktur'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ========================================================= dialog detail */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <ReceiptText className="h-4 w-4" />
                            {detail?.number}
                            {detail?.status === 'VOID' && <Chip tone="warning">Dibatalkan</Chip>}
                        </DialogTitle>
                        <DialogDescription className="text-2xs">
                            {detail && (
                                <>
                                    {formatDate(detail.date)} · {detail.supplier ?? '—'}
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-3 text-xs">
                            <div className="flex flex-wrap gap-1">
                                {detail.receipts.map((r) => (
                                    <Chip key={r}>{r}</Chip>
                                ))}
                            </div>

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
                                    <dt className="text-muted-foreground">Subtotal</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.subtotal)}</dd>
                                </div>
                                {detail.taxTotal !== 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Pajak</dt>
                                        <dd className="tabular font-mono">{formatRupiah(detail.taxTotal)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Total</dt>
                                    <dd className="tabular font-mono">{formatRupiah(detail.total)}</dd>
                                </div>
                            </dl>

                            {detail.author && <p className="text-muted-foreground text-2xs">oleh {detail.author}</p>}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" size="sm" onClick={() => setDetail(null)}>
                            Tutup
                        </Button>
                        {detail?.status === 'POSTED' && (
                            <Button variant="destructive" size="sm" onClick={bukaBatal}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> Batalkan Faktur
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
                            Penerimaan yang tercakup kembali menggantung dan bisa difakturkan ulang. Stok tidak tersentuh — pembatalan tidak dapat
                            diurungkan.
                        </DialogDescription>
                    </DialogHeader>

                    {errors?.purchase && <p className="text-2xs text-destructive font-medium">{errors.purchase}</p>}

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setBatalTerbuka(false)}>
                            Batal
                        </Button>
                        <Button variant="destructive" size="sm" onClick={batalkan} disabled={voidForm.processing}>
                            {voidForm.processing ? 'Membatalkan...' : 'Ya, batalkan faktur'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
