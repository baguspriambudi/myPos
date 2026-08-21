import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { Banknote, Minus, Plus, ScanLine, ShoppingCart, Trash2, TriangleAlert } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatQty, formatRupiah, hitungPembulatan } from '@/components/pos-ui';
import { branch, categories, products, productImage, type PaymentType, type Product } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kasir', href: '/kasir' }];

const METODE: { key: PaymentType; label: string }[] = [
    { key: 'CASH', label: 'Tunai' },
    { key: 'QRIS', label: 'QRIS' },
    { key: 'DEBIT', label: 'Debit' },
    { key: 'TRANSFER', label: 'Transfer' },
    { key: 'E_WALLET', label: 'E-Wallet' },
];

interface CartLine {
    key: string;
    product: Product;
    uomName: string;
    qty: number;
}

export default function KasirIndex() {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [lines, setLines] = useState<CartLine[]>([]);
    const [payment, setPayment] = useState<PaymentType>('CASH');
    const [payOpen, setPayOpen] = useState(false);
    const [cashReceived, setCashReceived] = useState('');
    const [doneNumber, setDoneNumber] = useState<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    /* ------------------------------------------------------------ katalog */

    const katalog = useMemo(() => {
        const q = search.trim().toLowerCase();
        return products.filter((p) => {
            if (!p.active) return false;
            if (category && p.category !== category) return false;
            if (!q) return true;
            return (
                p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || p.barcode.includes(q)
            );
        });
    }, [search, category]);

    /* ------------------------------------------------------------ keranjang */

    function tambah(product: Product, uomName?: string) {
        const uom = uomName ?? product.uoms[0].name;
        const key = `${product.id}-${uom}`;
        setLines((prev) => {
            const ada = prev.find((l) => l.key === key);
            if (ada) {
                return prev.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
            }
            return [...prev, { key, product, uomName: uom, qty: 1 }];
        });
    }

    function ubahQty(key: string, qty: number) {
        setLines((prev) =>
            prev.flatMap((l) => {
                if (l.key !== key) return [l];
                if (qty <= 0) return [];
                const bulat = l.product.isWeighted ? Math.round(qty * 100) / 100 : Math.round(qty);
                return [{ ...l, qty: bulat }];
            }),
        );
    }

    function gantiUom(key: string, uomName: string) {
        setLines((prev) => {
            const line = prev.find((l) => l.key === key);
            if (!line) return prev;
            const keyBaru = `${line.product.id}-${uomName}`;
            const tanpaLama = prev.filter((l) => l.key !== key);
            const ada = tanpaLama.find((l) => l.key === keyBaru);
            if (ada) {
                return tanpaLama.map((l) => (l.key === keyBaru ? { ...l, qty: l.qty + line.qty } : l));
            }
            return prev.map((l) => (l.key === key ? { ...l, key: keyBaru, uomName } : l));
        });
    }

    function hargaBaris(line: CartLine) {
        const uom = line.product.uoms.find((u) => u.name === line.uomName)!;
        return { uom, total: uom.price * line.qty, qtyBase: uom.factor * line.qty };
    }

    /* -------------------------------------------------------------- total */

    const netTotal = lines.reduce((sum, l) => sum + hargaBaris(l).total, 0);

    // Pembulatan hanya berlaku untuk pembayaran tunai (Addendum §51.4)
    const pembulatan =
        payment === 'CASH' ? hitungPembulatan(netTotal, branch.roundingUnit, branch.roundingMode) : 0;
    const dibayar = netTotal + pembulatan;

    // Stok tidak boleh minus: qty base per produk dijumlah lintas satuan
    const kurangStok = useMemo(() => {
        const perProduk = new Map<number, { product: Product; qtyBase: number }>();
        for (const l of lines) {
            const { qtyBase } = hargaBaris(l);
            const acc = perProduk.get(l.product.id);
            perProduk.set(l.product.id, {
                product: l.product,
                qtyBase: (acc?.qtyBase ?? 0) + qtyBase,
            });
        }
        return [...perProduk.values()].filter((x) => x.qtyBase > x.product.stock);
    }, [lines]);

    const uangDiterima = Number(cashReceived.replace(/\D/g, '')) || 0;
    const kembalian = uangDiterima - dibayar;
    const bisaBayar = lines.length > 0 && kurangStok.length === 0;
    const bisaSelesai = payment !== 'CASH' || uangDiterima >= dibayar;

    function selesaikan() {
        const urut = String(138 + Math.floor(Math.random() * 20)).padStart(6, '0');
        setDoneNumber(`INV/${branch.code}/2026-08/${urut}`);
        setLines([]);
        setCashReceived('');
        setPayOpen(false);
        setSearch('');
        searchRef.current?.focus();
        window.setTimeout(() => setDoneNumber(null), 4000);
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kasir" />

            <div className="flex min-h-0 flex-1 flex-col gap-3 p-4 md:p-5 lg:flex-row">
                {/* =============================================== katalog produk */}
                <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative min-w-56 flex-1">
                            <ScanLine className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
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
                                    category === null
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card hover:bg-accent',
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
                                        category === c
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'bg-card hover:bg-accent',
                                    )}
                                >
                                    {c}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                        {katalog.length === 0 ? (
                            <div className="flex h-40 flex-col items-center justify-center gap-2 text-muted-foreground">
                                <ShoppingCart className="h-6 w-6 opacity-50" />
                                <p className="text-sm">Produk tidak ditemukan.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                                {katalog.map((p) => {
                                    const habis = p.stock <= 0;
                                    return (
                                        <button
                                            key={p.id}
                                            onClick={() => tambah(p)}
                                            disabled={habis}
                                            className={cn(
                                                'group flex flex-col overflow-hidden rounded-lg border bg-card text-left transition-all',
                                                habis
                                                    ? 'cursor-not-allowed opacity-50'
                                                    : 'hover:border-primary/50 hover:shadow-sm active:scale-[0.98]',
                                            )}
                                        >
                                            <div className="relative aspect-square w-full overflow-hidden bg-muted">
                                                <img
                                                    src={productImage(p)}
                                                    alt={p.name}
                                                    loading="lazy"
                                                    width={1254}
                                                    height={1254}
                                                    className={cn(
                                                        'h-full w-full object-cover',
                                                        // Placeholder terang menyilaukan di mode gelap
                                                        !p.image && 'opacity-90 dark:opacity-25',
                                                    )}
                                                />
                                                {p.stock < p.minStock && !habis && (
                                                    <span className="absolute top-1.5 right-1.5 rounded bg-warning px-1.5 py-0.5 text-2xs font-semibold text-warning-foreground">
                                                        menipis
                                                    </span>
                                                )}
                                            </div>
                                            <div className="flex flex-1 flex-col gap-0.5 p-2">
                                                <p className="line-clamp-2 text-sm leading-tight font-medium">
                                                    {p.name}
                                                </p>
                                                <p className="mt-auto font-mono text-sm font-semibold text-primary">
                                                    {formatRupiah(p.uoms[0].price)}
                                                    <span className="ml-0.5 text-2xs font-normal text-muted-foreground">
                                                        /{p.baseUom}
                                                    </span>
                                                </p>
                                                <p className="font-mono text-2xs text-muted-foreground">
                                                    stok {formatQty(p.stock)} {p.baseUom}
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
                <aside className="flex w-full shrink-0 flex-col rounded-lg border bg-card lg:w-88">
                    <header className="flex shrink-0 items-center justify-between border-b px-3 py-2.5">
                        <h2 className="flex items-center gap-1.5 text-lg font-semibold">
                            <ShoppingCart className="h-4 w-4" />
                            Keranjang
                            {lines.length > 0 && (
                                <span className="rounded bg-primary/10 px-1.5 py-0.5 font-mono text-2xs text-primary">
                                    {lines.length}
                                </span>
                            )}
                        </h2>
                        {lines.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs text-muted-foreground"
                                onClick={() => setLines([])}
                            >
                                Kosongkan
                            </Button>
                        )}
                    </header>

                    {/* baris keranjang */}
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {lines.length === 0 ? (
                            <div className="flex h-full min-h-40 flex-col items-center justify-center gap-1.5 px-4 text-center text-muted-foreground">
                                {doneNumber ? (
                                    <>
                                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-success/15 text-success">
                                            <Banknote className="h-4 w-4" />
                                        </span>
                                        <p className="text-sm font-medium text-success">Transaksi tersimpan</p>
                                        <p className="font-mono text-2xs">{doneNumber}</p>
                                    </>
                                ) : (
                                    <>
                                        <ShoppingCart className="h-6 w-6 opacity-40" />
                                        <p className="text-sm">Belum ada item.</p>
                                        <p className="text-2xs">Scan barcode atau pilih produk di sebelah kiri.</p>
                                    </>
                                )}
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {lines.map((l) => {
                                    const { uom, total, qtyBase } = hargaBaris(l);
                                    const lebih = qtyBase > l.product.stock;
                                    return (
                                        <li key={l.key} className="px-3 py-2.5">
                                            <div className="flex items-start gap-2">
                                                <img
                                                    src={productImage(l.product)}
                                                    alt=""
                                                    loading="lazy"
                                                    className={cn(
                                                        'h-9 w-9 shrink-0 rounded-md border object-cover',
                                                        !l.product.image && 'opacity-90 dark:opacity-30',
                                                    )}
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm leading-tight font-medium">
                                                        {l.product.name}
                                                    </p>
                                                    <p className="font-mono text-2xs text-muted-foreground">
                                                        {formatRupiah(uom.price)} / {uom.name}
                                                    </p>
                                                </div>
                                                <button
                                                    onClick={() => ubahQty(l.key, 0)}
                                                    className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                                    aria-label={`Hapus ${l.product.name}`}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </button>
                                            </div>

                                            <div className="mt-2 flex items-center gap-2">
                                                {/* pemilih satuan */}
                                                {l.product.uoms.length > 1 && (
                                                    <div className="flex gap-0.5">
                                                        {l.product.uoms.map((u) => (
                                                            <button
                                                                key={u.name}
                                                                onClick={() => gantiUom(l.key, u.name)}
                                                                className={cn(
                                                                    'rounded border px-1.5 py-0.5 font-mono text-2xs transition-colors',
                                                                    u.name === l.uomName
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
                                                        onClick={() =>
                                                            ubahQty(l.key, l.qty - (l.product.isWeighted ? 0.25 : 1))
                                                        }
                                                        className="flex h-6 w-6 items-center justify-center rounded border transition-colors hover:bg-accent"
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
                                                        className="h-6 w-14 rounded border bg-transparent px-1 text-center font-mono text-xs tabular-nums [appearance:textfield] focus:ring-1 focus:ring-ring focus:outline-none [&::-webkit-inner-spin-button]:appearance-none"
                                                        aria-label={`Jumlah ${l.product.name}`}
                                                    />
                                                    <button
                                                        onClick={() =>
                                                            ubahQty(l.key, l.qty + (l.product.isWeighted ? 0.25 : 1))
                                                        }
                                                        className="flex h-6 w-6 items-center justify-center rounded border transition-colors hover:bg-accent"
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
                                                <p className="mt-1.5 flex items-center gap-1 text-2xs text-destructive">
                                                    <TriangleAlert className="h-3 w-3" />
                                                    Melebihi stok — tersedia {formatQty(l.product.stock)}{' '}
                                                    {l.product.baseUom}
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
                            {METODE.map((m) => (
                                <button
                                    key={m.key}
                                    onClick={() => setPayment(m.key)}
                                    className={cn(
                                        'flex-1 rounded-md border px-2 py-1 text-xs whitespace-nowrap transition-colors',
                                        payment === m.key
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'hover:bg-accent',
                                    )}
                                >
                                    {m.label}
                                </button>
                            ))}
                        </div>

                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Nilai penjualan</dt>
                                <dd className="font-mono tabular-nums">{formatRupiah(netTotal)}</dd>
                            </div>
                            {pembulatan !== 0 && (
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Pembulatan
                                        <span className="ml-1 text-2xs">({branch.roundingUnit})</span>
                                    </dt>
                                    <dd className="font-mono tabular-nums">{formatRupiah(pembulatan)}</dd>
                                </div>
                            )}
                            <div className="flex items-baseline justify-between border-t pt-1.5">
                                <dt className="font-medium">Total bayar</dt>
                                <dd className="font-mono text-2xl font-semibold tabular-nums">
                                    {formatRupiah(dibayar)}
                                </dd>
                            </div>
                        </dl>

                        {kurangStok.length > 0 && (
                            <p className="flex items-start gap-1.5 rounded-md bg-destructive/10 px-2.5 py-1.5 text-2xs text-destructive">
                                <TriangleAlert className="mt-px h-3 w-3 shrink-0" />
                                Stok tidak mencukupi untuk {kurangStok.length} produk. Toko ini tidak mengizinkan stok
                                minus.
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
                        <div className="rounded-lg bg-muted/60 px-3 py-2.5">
                            <p className="label-eyebrow">Total bayar</p>
                            <p className="font-mono text-3xl font-semibold tabular-nums">{formatRupiah(dibayar)}</p>
                            {pembulatan !== 0 && (
                                <p className="mt-0.5 text-2xs text-muted-foreground">
                                    Nilai penjualan {formatRupiah(netTotal)}, dibulatkan {formatRupiah(pembulatan)}.
                                    Omzet dan pajak tidak berubah.
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
                                        className="rounded-md border px-2 py-1 text-xs transition-colors hover:bg-accent"
                                    >
                                        Uang pas
                                    </button>
                                    {[10_000, 20_000, 50_000, 100_000]
                                        .filter((n) => n >= dibayar)
                                        .map((n) => (
                                            <button
                                                key={n}
                                                onClick={() => setCashReceived(String(n))}
                                                className="rounded-md border px-2 py-1 font-mono text-xs transition-colors hover:bg-accent"
                                            >
                                                {formatRupiah(n)}
                                            </button>
                                        ))}
                                </div>

                                <div className="flex items-baseline justify-between border-t pt-2">
                                    <span className="text-sm text-muted-foreground">Kembalian</span>
                                    <span
                                        className={cn(
                                            'font-mono text-xl font-semibold tabular-nums',
                                            kembalian < 0 ? 'text-destructive' : 'text-success',
                                        )}
                                    >
                                        {formatRupiah(Math.max(kembalian, 0))}
                                    </span>
                                </div>
                                {kembalian < 0 && (
                                    <p className="text-2xs text-destructive">
                                        Kurang {formatRupiah(-kembalian)}.
                                    </p>
                                )}
                            </div>
                        ) : (
                            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                Pembayaran {METODE.find((m) => m.key === payment)?.label} dibayar persis sebesar nilai
                                penjualan — tidak ada pembulatan.
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setPayOpen(false)}>
                            Batal
                        </Button>
                        <Button size="sm" disabled={!bisaSelesai} onClick={selesaikan}>
                            Selesai &amp; Cetak
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
