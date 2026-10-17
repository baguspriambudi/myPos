import { formatQty, formatRupiah, hitungPembulatan } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Banknote, CalendarOff, Minus, Plus, Printer, ScanLine, ShoppingCart, Trash2, TriangleAlert, Wallet } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kasir', href: '/kasir' }];

const NO_IMAGE = '/images/no-image.png';

const LABEL_METODE: Record<string, string> = {
    CASH: 'Tunai',
    QRIS: 'QRIS',
    DEBIT: 'Debit',
    CREDIT_CARD: 'Kartu Kredit',
    TRANSFER: 'Transfer',
    E_WALLET: 'E-Wallet',
    CREDIT: 'Piutang (Kredit)',
};

interface Satuan {
    id: number;
    name: string;
    factor: number;
    /** null = satuan ini belum punya harga di daftar harga mana pun. */
    price: number | null;
}

interface Produk {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    category: string | null;
    isWeighted: boolean;
    tracksStock: boolean;
    baseUom: string | null;
    stock: number;
    uoms: Satuan[];
}

interface Pelanggan {
    id: number;
    name: string;
    isMember: boolean;
    isDefault: boolean;
    creditLimit: number;
    receivableOutstanding: number;
}

interface Nota {
    number: string;
    date: string;
    customer: string | null;
    paymentType: string | null;
    netTotal: number;
    rounding: number;
    paidTotal: number;
    receivableAmount: number | null;
    cashReceived: number | null;
    change: number | null;
    lines: { name: string; qty: number; uom: string; unitPrice: number; lineTotal: number }[];
}

interface BarisKeranjang {
    key: string;
    product: Produk;
    uom: Satuan;
    qty: number;
}

interface Shift {
    id: number;
    number: string;
    openedAt: string;
    openingCash: number;
    openingSource: 'MANUAL' | 'ROLLOVER';
}

/** Satuan pertama yang sudah berharga; satuan tanpa harga tidak boleh dijual. */
function satuanTerpakai(p: Produk): Satuan | undefined {
    return p.uoms.find((u) => u.price !== null);
}

function uuidBaru(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export default function KasirIndex() {
    const page = usePage().props as unknown as {
        products: Produk[];
        categories: string[];
        customers: Pelanggan[];
        selectedCustomerId: number | null;
        canSell: boolean;
        periodProblem: string | null;
        methods: string[];
        creditMethod: string;
        currentShift: Shift | null;
        auth: { owner: { roundingUnit: number; roundingMode: 'NEAREST' | 'DOWN' | 'UP' } | null };
        flash: { nota?: Nota | null };
        errors: Record<string, string>;
    };

    const { products, categories, customers, selectedCustomerId, canSell, periodProblem, methods, creditMethod, currentShift, errors } = page;
    const roundingUnit = page.auth.owner?.roundingUnit ?? 0;
    const roundingMode = page.auth.owner?.roundingMode ?? 'NEAREST';

    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [lines, setLines] = useState<BarisKeranjang[]>([]);
    const [payment, setPayment] = useState<string>('CASH');
    const [payOpen, setPayOpen] = useState(false);
    const [cashReceived, setCashReceived] = useState('');
    const [processing, setProcessing] = useState(false);
    const [notaOpen, setNotaOpen] = useState(false);
    const [shiftBukaOpen, setShiftBukaOpen] = useState(false);
    const [shiftTutupOpen, setShiftTutupOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    // Kunci idempotensi: percobaan ulang karena jaringan lambat memakai nilai
    // yang sama sehingga server mengembalikan nota yang sudah jadi, bukan
    // menerbitkan nota kedua.
    const uuidRef = useRef<string>(uuidBaru());

    const nota = page.flash?.nota ?? null;
    const nomorNota = nota?.number ?? null;

    // Struk muncul sendiri begitu nota baru terbit; nomornya yang dipantau,
    // bukan objeknya, supaya render ulang biasa tidak membuka dialog lagi.
    useEffect(() => {
        if (nomorNota) {
            setNotaOpen(true);
        }
    }, [nomorNota]);

    /* ---------------------------------------------------------- shift kasir */
    // Bukan prasyarat berjualan — sekadar menaut nota yang terbit selama shift
    // ini terbuka supaya bisa direkonsiliasi saat ditutup (lihat SalePoster).

    const shiftBukaForm = useForm({ opening_cash: '' });
    const shiftTutupForm = useForm({ actual_cash: '', close_note: '' });

    function bukaDialogShift() {
        shiftBukaForm.setData('opening_cash', '');
        shiftBukaForm.clearErrors();
        setShiftBukaOpen(true);
    }

    function simpanBukaShift() {
        shiftBukaForm.post('/kasir/shift/buka', {
            preserveScroll: true,
            onSuccess: () => setShiftBukaOpen(false),
        });
    }

    function bukaDialogTutupShift() {
        shiftTutupForm.setData({ actual_cash: '', close_note: '' });
        shiftTutupForm.clearErrors();
        setShiftTutupOpen(true);
    }

    function simpanTutupShift() {
        if (!currentShift) return;
        shiftTutupForm.post(`/kasir/shift/${currentShift.id}/tutup`, {
            preserveScroll: true,
            onSuccess: () => setShiftTutupOpen(false),
        });
    }

    /* ------------------------------------------------------------ katalog */

    const katalog = useMemo(() => {
        const q = search.trim().toLowerCase();
        return products.filter((p) => {
            if (category && p.category !== category) return false;
            if (!q) return true;
            return p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || (p.barcode ?? '').includes(q);
        });
    }, [products, search, category]);

    /* ---------------------------------------------------------- keranjang */

    function tambah(product: Produk, satuan?: Satuan) {
        const uom = satuan ?? satuanTerpakai(product);
        if (!uom) return;

        const key = `${product.id}-${uom.id}`;
        setLines((prev) => {
            const ada = prev.find((l) => l.key === key);
            if (ada) {
                return prev.map((l) => (l.key === key ? { ...l, qty: l.qty + (product.isWeighted ? 0.25 : 1) } : l));
            }
            return [...prev, { key, product, uom, qty: product.isWeighted ? 0.25 : 1 }];
        });
    }

    function ubahQty(key: string, qty: number) {
        setLines((prev) =>
            prev
                .map((l) => {
                    if (l.key !== key) return l;
                    const bulat = l.product.isWeighted ? Math.round(qty * 100) / 100 : Math.round(qty);
                    return { ...l, qty: bulat };
                })
                .filter((l) => l.qty > 0),
        );
    }

    function gantiUom(key: string, uom: Satuan) {
        setLines((prev) => {
            const line = prev.find((l) => l.key === key);
            if (!line) return prev;
            const keyBaru = `${line.product.id}-${uom.id}`;
            const tanpaLama = prev.filter((l) => l.key !== key);
            const ada = tanpaLama.find((l) => l.key === keyBaru);
            if (ada) {
                return tanpaLama.map((l) => (l.key === keyBaru ? { ...l, qty: l.qty + line.qty } : l));
            }
            return [...tanpaLama, { ...line, key: keyBaru, uom }];
        });
    }

    function hargaBaris(line: BarisKeranjang) {
        const harga = line.uom.price ?? 0;
        return { total: Math.round(harga * line.qty), qtyBase: line.qty * line.uom.factor };
    }

    /* --------------------------------------------------------------- uang */

    const netTotal = lines.reduce((sum, l) => sum + hargaBaris(l).total, 0);
    // Pembulatan hanya berlaku untuk pembayaran tunai (Addendum 51.4); nilai
    // penjualan dan dasar pajaknya sendiri tidak ikut berubah.
    const pembulatan = payment === 'CASH' ? hitungPembulatan(netTotal, roundingUnit, roundingMode) : 0;
    const dibayar = netTotal + pembulatan;

    const kurangStok = useMemo(() => {
        const perProduk = new Map<number, { product: Produk; qtyBase: number }>();
        for (const l of lines) {
            if (!l.product.tracksStock) continue;
            const { qtyBase } = hargaBaris(l);
            const acc = perProduk.get(l.product.id);
            if (acc) acc.qtyBase += qtyBase;
            else perProduk.set(l.product.id, { product: l.product, qtyBase });
        }
        return [...perProduk.values()].filter((x) => x.qtyBase > x.product.stock);
    }, [lines]);

    const uangDiterima = Number(cashReceived.replace(/\D/g, '')) || 0;
    const kembalian = uangDiterima - dibayar;
    const terkunci = !canSell || periodProblem !== null;
    const bisaBayar = lines.length > 0 && kurangStok.length === 0 && !terkunci;

    /* ------------------------------------------------------------- kredit */

    const pelangganTerpilih = customers.find((c) => c.id === selectedCustomerId) ?? null;
    const sisaLimitKredit = pelangganTerpilih ? pelangganTerpilih.creditLimit - pelangganTerpilih.receivableOutstanding : 0;
    const bisaKredit = !!pelangganTerpilih && !pelangganTerpilih.isDefault && pelangganTerpilih.creditLimit > 0 && dibayar <= sisaLimitKredit;
    const isKredit = payment === creditMethod;

    const bisaSelesai = (payment !== 'CASH' || uangDiterima >= dibayar) && (!isKredit || bisaKredit) && !processing;

    function selesaikan() {
        setProcessing(true);
        router.post(
            '/kasir/nota',
            {
                payment_type: payment,
                customer_id: selectedCustomerId,
                cash_received: payment === 'CASH' ? uangDiterima : null,
                local_uuid: uuidRef.current,
                lines: lines.map((l) => ({ product_id: l.product.id, uom_id: l.uom.id, qty: l.qty })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setLines([]);
                    setCashReceived('');
                    setPayOpen(false);
                    setSearch('');
                    uuidRef.current = uuidBaru();
                    searchRef.current?.focus();
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    function gantiPelanggan(id: number) {
        // Harga katalog ikut berubah mengikuti jenis pelanggan, jadi katalognya
        // dimuat ulang — isi keranjang sengaja dipertahankan.
        router.get('/kasir', { pelanggan: id }, { preserveState: true, preserveScroll: true, only: ['products', 'selectedCustomerId'] });
    }

    /** Enter pada kolom cari: bila cocok persis satu barcode, langsung masuk keranjang. */
    function onSearchKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key !== 'Enter') return;
        const q = search.trim();
        if (!q) return;
        const cocok = products.find((p) => p.barcode === q) ?? (katalog.length === 1 ? katalog[0] : null);
        if (cocok) {
            tambah(cocok);
            setSearch('');
        }
    }

    const pesanGagal = Object.values(errors ?? {})[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kasir" />

            <div className="flex h-[calc(100svh-4rem)] flex-col gap-3 overflow-hidden p-4 md:p-5 lg:flex-row">
                {/* =============================================== katalog produk */}
                <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-3">
                    {terkunci && (
                        <div className="text-2xs border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-1.5 rounded-md border px-3 py-2">
                            <CalendarOff className="mt-px h-3.5 w-3.5 shrink-0" />
                            <span>{periodProblem ?? 'Lokasi aktif Anda bukan lokasi penjualan sehingga tidak dapat menerbitkan nota.'}</span>
                        </div>
                    )}

                    {!terkunci && (
                        <div className="text-2xs bg-muted/40 flex items-center justify-between gap-2 rounded-md border px-3 py-1.5">
                            <span className="flex items-center gap-1.5">
                                <Wallet className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                {currentShift ? (
                                    <>
                                        Shift <span className="font-mono font-medium">{currentShift.number}</span> terbuka sejak{' '}
                                        {new Date(currentShift.openedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })} ·
                                        kas awal {formatRupiah(currentShift.openingCash)}
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">Belum ada shift terbuka — boleh tetap berjualan tanpa shift.</span>
                                )}
                            </span>
                            {currentShift ? (
                                <button
                                    type="button"
                                    onClick={bukaDialogTutupShift}
                                    className="text-muted-foreground hover:text-foreground shrink-0 underline-offset-2 hover:underline"
                                >
                                    Tutup shift
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={bukaDialogShift}
                                    className="text-primary shrink-0 underline-offset-2 hover:underline"
                                >
                                    Buka shift
                                </button>
                            )}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative min-w-56 flex-1">
                            <ScanLine className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                            <Input
                                ref={searchRef}
                                autoFocus
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={onSearchKeyDown}
                                placeholder="Scan barcode atau cari nama produk..."
                                className="h-9 pl-8"
                            />
                        </div>
                        <div className="flex flex-wrap gap-1">
                            <button
                                onClick={() => setCategory(null)}
                                className={cn(
                                    'rounded-md border px-2.5 py-1 text-xs transition-colors',
                                    category === null ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent',
                                )}
                            >
                                Semua
                            </button>
                            {categories.map((c) => (
                                <button
                                    key={c}
                                    onClick={() => setCategory(c)}
                                    className={cn(
                                        'rounded-md border px-2.5 py-1 text-xs transition-colors',
                                        category === c ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent',
                                    )}
                                >
                                    {c}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                        {katalog.length === 0 ? (
                            <div className="text-muted-foreground flex h-40 flex-col items-center justify-center gap-2">
                                <ShoppingCart className="h-6 w-6 opacity-50" />
                                <p className="text-sm">
                                    {products.length === 0 ? 'Belum ada produk berharga yang dapat dijual.' : 'Produk tidak ditemukan.'}
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                                {katalog.map((p) => {
                                    const utama = satuanTerpakai(p);
                                    const habis = p.tracksStock && p.stock <= 0;
                                    return (
                                        <button
                                            key={p.id}
                                            onClick={() => tambah(p)}
                                            disabled={habis || terkunci}
                                            className={cn(
                                                'group bg-card flex flex-col overflow-hidden rounded-lg border text-left transition-all',
                                                habis || terkunci
                                                    ? 'cursor-not-allowed opacity-50'
                                                    : 'hover:border-primary/50 hover:shadow-sm active:scale-[0.98]',
                                            )}
                                        >
                                            <div className="bg-muted relative aspect-square w-full overflow-hidden">
                                                <img
                                                    src={NO_IMAGE}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-full w-full object-cover opacity-90 dark:opacity-25"
                                                />
                                                {!p.tracksStock && (
                                                    <span className="text-2xs bg-primary/90 text-primary-foreground absolute top-1.5 right-1.5 rounded px-1.5 py-0.5 font-semibold">
                                                        jasa
                                                    </span>
                                                )}
                                            </div>
                                            <div className="flex flex-1 flex-col gap-0.5 p-2">
                                                <p className="line-clamp-2 text-sm leading-tight font-medium">{p.name}</p>
                                                <p className="text-primary mt-auto font-mono text-sm font-semibold">
                                                    {formatRupiah(utama?.price ?? 0)}
                                                    <span className="text-2xs text-muted-foreground ml-0.5 font-normal">/{utama?.name}</span>
                                                </p>
                                                <p className="text-2xs text-muted-foreground font-mono">
                                                    {p.tracksStock ? `stok ${formatQty(p.stock)} ${p.baseUom ?? ''}` : 'tanpa stok'}
                                                </p>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>

                {/* ==================================================== keranjang */}
                <aside className="bg-card flex w-full shrink-0 flex-col rounded-lg border lg:w-88">
                    <header className="shrink-0 space-y-2 border-b px-3 py-2.5">
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-1.5 text-lg font-semibold">
                                <ShoppingCart className="h-4 w-4" />
                                Keranjang
                                {lines.length > 0 && (
                                    <span className="bg-primary/10 text-primary text-2xs rounded px-1.5 py-0.5 font-mono">{lines.length}</span>
                                )}
                            </h2>
                            {lines.length > 0 && (
                                <Button variant="ghost" size="sm" className="text-muted-foreground h-7 text-xs" onClick={() => setLines([])}>
                                    Kosongkan
                                </Button>
                            )}
                        </div>

                        <select
                            value={selectedCustomerId ?? ''}
                            onChange={(e) => gantiPelanggan(Number(e.target.value))}
                            className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                            aria-label="Pelanggan"
                        >
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                    {c.isMember ? ' · member' : ''}
                                </option>
                            ))}
                        </select>
                    </header>

                    {/* baris keranjang */}
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {lines.length === 0 ? (
                            <div className="text-muted-foreground flex h-full min-h-40 flex-col items-center justify-center gap-1.5 px-4 text-center">
                                <ShoppingCart className="h-6 w-6 opacity-40" />
                                <p className="text-sm">Belum ada item.</p>
                                <p className="text-2xs">Scan barcode atau pilih produk di sebelah kiri.</p>
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {lines.map((l) => {
                                    const { total, qtyBase } = hargaBaris(l);
                                    const lebih = l.product.tracksStock && qtyBase > l.product.stock;
                                    const berharga = l.product.uoms.filter((u) => u.price !== null);
                                    return (
                                        <li key={l.key} className="px-3 py-2.5">
                                            <div className="flex items-start gap-2">
                                                <img
                                                    src={NO_IMAGE}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-9 w-9 shrink-0 rounded-md border object-cover opacity-90 dark:opacity-30"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm leading-tight font-medium">{l.product.name}</p>
                                                    <p className="text-2xs text-muted-foreground font-mono">
                                                        {formatRupiah(l.uom.price ?? 0)} / {l.uom.name}
                                                    </p>
                                                </div>
                                                <button
                                                    onClick={() => ubahQty(l.key, 0)}
                                                    className="text-muted-foreground hover:bg-destructive/10 hover:text-destructive shrink-0 rounded p-1 transition-colors"
                                                    aria-label={`Hapus ${l.product.name}`}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </button>
                                            </div>

                                            <div className="mt-2 flex items-center gap-2">
                                                {berharga.length > 1 && (
                                                    <div className="flex gap-0.5">
                                                        {berharga.map((u) => (
                                                            <button
                                                                key={u.id}
                                                                onClick={() => gantiUom(l.key, u)}
                                                                className={cn(
                                                                    'text-2xs rounded border px-1.5 py-0.5 font-mono transition-colors',
                                                                    u.id === l.uom.id
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'hover:bg-accent',
                                                                )}
                                                            >
                                                                {u.name}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}

                                                <div className="ml-auto flex items-center gap-1">
                                                    <button
                                                        onClick={() => ubahQty(l.key, l.qty - (l.product.isWeighted ? 0.25 : 1))}
                                                        className="hover:bg-accent flex h-6 w-6 items-center justify-center rounded border transition-colors"
                                                        aria-label="Kurangi"
                                                    >
                                                        <Minus className="h-3 w-3" />
                                                    </button>
                                                    <input
                                                        value={l.qty}
                                                        onChange={(e) => ubahQty(l.key, Number(e.target.value))}
                                                        type="number"
                                                        step={l.product.isWeighted ? 0.01 : 1}
                                                        min={0}
                                                        className="focus:ring-ring h-6 w-14 [appearance:textfield] rounded border bg-transparent px-1 text-center font-mono text-xs tabular-nums focus:ring-1 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none"
                                                        aria-label={`Jumlah ${l.product.name}`}
                                                    />
                                                    <button
                                                        onClick={() => ubahQty(l.key, l.qty + (l.product.isWeighted ? 0.25 : 1))}
                                                        className="hover:bg-accent flex h-6 w-6 items-center justify-center rounded border transition-colors"
                                                        aria-label="Tambah"
                                                    >
                                                        <Plus className="h-3 w-3" />
                                                    </button>
                                                </div>

                                                <span className="w-20 shrink-0 text-right font-mono text-sm font-medium tabular-nums">
                                                    {formatRupiah(total)}
                                                </span>
                                            </div>

                                            {lebih && (
                                                <p className="text-2xs text-destructive mt-1.5 flex items-center gap-1">
                                                    <TriangleAlert className="h-3 w-3" />
                                                    Melebihi stok — tersedia {formatQty(l.product.stock)} {l.product.baseUom}
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>

                    {/* metode bayar + total */}
                    <div className="shrink-0 space-y-2.5 border-t p-3">
                        <div className="flex flex-wrap gap-1">
                            {methods.map((m) => (
                                <button
                                    key={m}
                                    onClick={() => setPayment(m)}
                                    className={cn(
                                        'flex-1 rounded-md border px-2 py-1 text-xs whitespace-nowrap transition-colors',
                                        payment === m ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent',
                                    )}
                                >
                                    {LABEL_METODE[m] ?? m}
                                </button>
                            ))}
                            <button
                                key={creditMethod}
                                disabled={!pelangganTerpilih || pelangganTerpilih.isDefault || pelangganTerpilih.creditLimit <= 0}
                                onClick={() => setPayment(creditMethod)}
                                title={
                                    pelangganTerpilih && !pelangganTerpilih.isDefault && pelangganTerpilih.creditLimit > 0
                                        ? `Sisa limit ${pelangganTerpilih.name}: ${formatRupiah(sisaLimitKredit)}`
                                        : 'Pilih pelanggan berlimit piutang lebih dulu'
                                }
                                className={cn(
                                    'flex-1 rounded-md border px-2 py-1 text-xs whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                    payment === creditMethod ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent',
                                )}
                            >
                                Kredit
                            </button>
                        </div>

                        {isKredit && pelangganTerpilih && (
                            <p className={cn('text-2xs rounded-md px-2 py-1', bisaKredit ? 'bg-muted text-muted-foreground' : 'bg-destructive/10 text-destructive')}>
                                Sisa limit {pelangganTerpilih.name} setelah nota ini:{' '}
                                {formatRupiah(bisaKredit ? sisaLimitKredit - dibayar : sisaLimitKredit)}
                            </p>
                        )}

                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Nilai penjualan</dt>
                                <dd className="font-mono tabular-nums">{formatRupiah(netTotal)}</dd>
                            </div>
                            {pembulatan !== 0 && (
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Pembulatan
                                        <span className="text-2xs ml-1">({formatRupiah(roundingUnit)})</span>
                                    </dt>
                                    <dd className="font-mono tabular-nums">{formatRupiah(pembulatan)}</dd>
                                </div>
                            )}
                            <div className="flex items-baseline justify-between border-t pt-1.5">
                                <dt className="font-medium">Total bayar</dt>
                                <dd className="font-mono text-2xl font-semibold tabular-nums">{formatRupiah(dibayar)}</dd>
                            </div>
                        </dl>

                        {kurangStok.length > 0 && (
                            <p className="text-2xs text-destructive bg-destructive/10 flex items-start gap-1.5 rounded-md px-2.5 py-1.5">
                                <TriangleAlert className="mt-px h-3 w-3 shrink-0" />
                                Stok tidak mencukupi untuk {kurangStok.length} produk. Toko ini tidak mengizinkan stok minus.
                            </p>
                        )}

                        {pesanGagal && (
                            <p className="text-2xs text-destructive bg-destructive/10 flex items-start gap-1.5 rounded-md px-2.5 py-1.5">
                                <TriangleAlert className="mt-px h-3 w-3 shrink-0" />
                                {pesanGagal}
                            </p>
                        )}

                        <Button className="h-10 w-full text-base" disabled={!bisaBayar} onClick={() => setPayOpen(true)}>
                            <Banknote className="mr-1.5 h-4 w-4" /> Bayar
                        </Button>
                    </div>
                </aside>
            </div>

            {/* ==================================================== dialog bayar */}
            <Dialog open={payOpen} onOpenChange={setPayOpen}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Pembayaran</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="bg-muted/60 rounded-lg px-3 py-2.5">
                            <p className="label-eyebrow">Total bayar</p>
                            <p className="font-mono text-3xl font-semibold tabular-nums">{formatRupiah(dibayar)}</p>
                            {pembulatan !== 0 && (
                                <p className="text-2xs text-muted-foreground mt-0.5">
                                    Nilai penjualan {formatRupiah(netTotal)}, dibulatkan {formatRupiah(pembulatan)}. Omzet dan pajak tidak berubah.
                                </p>
                            )}
                        </div>

                        {payment === 'CASH' ? (
                            <div className="space-y-2">
                                <label className="label-eyebrow" htmlFor="uang-diterima">
                                    Uang diterima
                                </label>
                                <Input
                                    id="uang-diterima"
                                    autoFocus
                                    inputMode="numeric"
                                    value={cashReceived}
                                    onChange={(e) => setCashReceived(e.target.value.replace(/\D/g, ''))}
                                    placeholder="0"
                                    className="h-10 text-right font-mono text-lg"
                                />
                                <div className="flex flex-wrap gap-1">
                                    <button
                                        onClick={() => setCashReceived(String(dibayar))}
                                        className="hover:bg-accent rounded-md border px-2 py-1 text-xs transition-colors"
                                    >
                                        Uang pas
                                    </button>
                                    {[10_000, 20_000, 50_000, 100_000]
                                        .filter((n) => n >= dibayar)
                                        .map((n) => (
                                            <button
                                                key={n}
                                                onClick={() => setCashReceived(String(n))}
                                                className="hover:bg-accent rounded-md border px-2 py-1 font-mono text-xs transition-colors"
                                            >
                                                {formatRupiah(n)}
                                            </button>
                                        ))}
                                </div>

                                <div className="flex items-baseline justify-between border-t pt-2">
                                    <span className="text-muted-foreground text-sm">Kembalian</span>
                                    <span
                                        className={cn(
                                            'font-mono text-xl font-semibold tabular-nums',
                                            kembalian < 0 ? 'text-destructive' : 'text-success',
                                        )}
                                    >
                                        {formatRupiah(Math.max(kembalian, 0))}
                                    </span>
                                </div>
                                {kembalian < 0 && <p className="text-2xs text-destructive">Kurang {formatRupiah(-kembalian)}.</p>}
                            </div>
                        ) : isKredit ? (
                            <p className={cn('rounded-md px-3 py-2 text-xs', bisaKredit ? 'bg-muted text-muted-foreground' : 'bg-destructive/10 text-destructive')}>
                                Tidak ada uang diterima — tercatat sebagai piutang atas nama{' '}
                                <span className="font-medium">{pelangganTerpilih?.name}</span>.{' '}
                                {bisaKredit
                                    ? `Sisa limit setelahnya ${formatRupiah(sisaLimitKredit - dibayar)}.`
                                    : 'Melebihi sisa limit piutangnya — pilih metode lain.'}
                            </p>
                        ) : (
                            <p className="bg-muted text-muted-foreground rounded-md px-3 py-2 text-xs">
                                Pembayaran {LABEL_METODE[payment] ?? payment} dibayar persis sebesar nilai penjualan — tidak ada pembulatan.
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setPayOpen(false)}>
                            Batal
                        </Button>
                        <Button size="sm" disabled={!bisaSelesai} onClick={selesaikan}>
                            {processing ? 'Menyimpan...' : 'Selesai & Cetak'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ===================================================== struk nota */}
            <Dialog open={notaOpen} onOpenChange={setNotaOpen}>
                <DialogContent className="sm:max-w-xs">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-1.5 text-base">
                            <Printer className="h-4 w-4" /> Nota tersimpan
                        </DialogTitle>
                    </DialogHeader>

                    {nota && (
                        <div className="space-y-3 text-xs">
                            <div>
                                <p className="font-mono text-sm font-semibold">{nota.number}</p>
                                <p className="text-2xs text-muted-foreground">
                                    {nota.date} · {nota.customer ?? 'Tanpa pelanggan'} · {LABEL_METODE[nota.paymentType ?? ''] ?? '—'}
                                </p>
                            </div>

                            <ul className="divide-y border-y">
                                {nota.lines.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0">
                                            <span className="block truncate">{l.name}</span>
                                            <span className="text-2xs text-muted-foreground font-mono">
                                                {formatQty(l.qty)} {l.uom} × {formatRupiah(l.unitPrice)}
                                            </span>
                                        </span>
                                        <span className="shrink-0 font-mono tabular-nums">{formatRupiah(l.lineTotal)}</span>
                                    </li>
                                ))}
                            </ul>

                            <dl className="space-y-1">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Nilai penjualan</dt>
                                    <dd className="font-mono tabular-nums">{formatRupiah(nota.netTotal)}</dd>
                                </div>
                                {nota.rounding !== 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Pembulatan</dt>
                                        <dd className="font-mono tabular-nums">{formatRupiah(nota.rounding)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-1 font-medium">
                                    <dt>Dibayar</dt>
                                    <dd className="font-mono tabular-nums">{formatRupiah(nota.paidTotal)}</dd>
                                </div>
                                {nota.receivableAmount !== null && (
                                    <div className="text-destructive flex justify-between font-medium">
                                        <dt>Piutang (belum dibayar)</dt>
                                        <dd className="font-mono tabular-nums">{formatRupiah(nota.receivableAmount)}</dd>
                                    </div>
                                )}
                                {nota.cashReceived !== null && (
                                    <>
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">Uang diterima</dt>
                                            <dd className="font-mono tabular-nums">{formatRupiah(nota.cashReceived)}</dd>
                                        </div>
                                        <div className="text-success flex justify-between font-medium">
                                            <dt>Kembalian</dt>
                                            <dd className="font-mono tabular-nums">{formatRupiah(nota.change ?? 0)}</dd>
                                        </div>
                                    </>
                                )}
                            </dl>
                        </div>
                    )}

                    <DialogFooter>
                        <Button size="sm" onClick={() => setNotaOpen(false)}>
                            Tutup
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ==================================================== dialog buka shift */}
            <Dialog open={shiftBukaOpen} onOpenChange={setShiftBukaOpen}>
                <DialogContent className="sm:max-w-xs">
                    <DialogHeader>
                        <DialogTitle>Buka shift</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <label className="text-muted-foreground text-2xs" htmlFor="kas-awal">
                            Kas awal di laci
                        </label>
                        <Input
                            id="kas-awal"
                            type="number"
                            inputMode="numeric"
                            min={0}
                            autoFocus
                            value={shiftBukaForm.data.opening_cash}
                            onChange={(e) => shiftBukaForm.setData('opening_cash', e.target.value)}
                            placeholder="Kosongkan untuk memakai kas akhir shift sebelumnya"
                            className="h-9 text-right font-mono"
                        />
                        {shiftBukaForm.errors.opening_cash && (
                            <p className="text-destructive text-2xs font-medium">{shiftBukaForm.errors.opening_cash}</p>
                        )}
                        {errors?.shift && <p className="text-destructive text-2xs font-medium">{errors.shift}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setShiftBukaOpen(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanBukaShift} disabled={shiftBukaForm.processing}>
                            {shiftBukaForm.processing ? 'Membuka...' : 'Buka shift'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================== dialog tutup shift */}
            <Dialog open={shiftTutupOpen} onOpenChange={setShiftTutupOpen}>
                <DialogContent className="sm:max-w-xs">
                    <DialogHeader>
                        <DialogTitle>Tutup shift {currentShift?.number}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="space-y-1.5">
                            <label className="text-muted-foreground text-2xs" htmlFor="kas-aktual">
                                Hasil hitung kas fisik saat ini
                            </label>
                            <Input
                                id="kas-aktual"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                autoFocus
                                value={shiftTutupForm.data.actual_cash}
                                onChange={(e) => shiftTutupForm.setData('actual_cash', e.target.value)}
                                className="h-9 text-right font-mono"
                            />
                            {shiftTutupForm.errors.actual_cash && (
                                <p className="text-destructive text-2xs font-medium">{shiftTutupForm.errors.actual_cash}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-muted-foreground text-2xs" htmlFor="catatan-tutup">
                                Catatan (opsional)
                            </label>
                            <textarea
                                id="catatan-tutup"
                                rows={2}
                                value={shiftTutupForm.data.close_note}
                                onChange={(e) => shiftTutupForm.setData('close_note', e.target.value)}
                                className="border-input bg-background focus:ring-ring w-full rounded-md border px-2 py-1.5 text-sm shadow-xs focus:ring-2 focus:ring-offset-2 focus:outline-none"
                            />
                        </div>

                        {errors?.shift && <p className="text-destructive text-2xs font-medium">{errors.shift}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setShiftTutupOpen(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpanTutupShift} disabled={shiftTutupForm.processing}>
                            {shiftTutupForm.processing ? 'Menutup...' : 'Tutup shift'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
