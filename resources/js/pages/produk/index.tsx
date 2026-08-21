import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Package, Plus, Scale } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    EmptyRow,
    PageHeader,
    Panel,
    SearchInput,
    TablePagination,
    formatQty,
    formatRupiah,
    usePagination,
} from '@/components/pos-ui';
import { categories, products, type Product } from '@/data/dummy';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Produk', href: '/produk' },
];

function stockTone(p: Product) {
    if (p.stock <= 0) return 'text-destructive';
    if (p.stock < p.minStock) return 'text-warning';
    return 'text-foreground';
}

export default function ProdukIndex() {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [detail, setDetail] = useState<Product | null>(null);

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return products.filter((p) => {
            const cocokKategori = !category || p.category === category;
            const cocokCari =
                !q ||
                p.name.toLowerCase().includes(q) ||
                p.sku.toLowerCase().includes(q) ||
                p.barcode.includes(q);
            return cocokKategori && cocokCari;
        });
    }, [search, category]);

    const pagination = usePagination(visible, 10);

    const nilaiPersediaan = products.reduce((sum, p) => sum + p.stock * p.avgCost, 0);
    const stokMenipis = products.filter((p) => p.active && p.stock < p.minStock).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Produk" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Produk"
                    subtitle="Master produk beserta satuan dan konversinya. Stok selalu disimpan dalam satuan dasar."
                    action={
                        <Button size="sm">
                            <Plus className="mr-1 h-3.5 w-3.5" /> Produk Baru
                        </Button>
                    }
                />

                {/* -------------------------------------------------- ringkasan */}
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Total produk</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{products.length}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Nilai persediaan</p>
                        <p className="mt-1 font-mono text-xl font-semibold tabular">{formatRupiah(nilaiPersediaan)}</p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3">
                        <p className="label-eyebrow">Stok menipis</p>
                        <p className="mt-1 font-mono text-xl font-semibold text-warning tabular">{stokMenipis}</p>
                    </div>
                </div>

                {/* ----------------------------------------------------- filter */}
                <div className="flex flex-wrap items-center gap-2">
                    <SearchInput
                        value={search}
                        onChange={setSearch}
                        placeholder="Cari nama, SKU, atau barcode..."
                        className="w-full sm:max-w-xs"
                    />
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

                {/* ------------------------------------------------------ tabel */}
                <Panel title="Daftar Produk" description={`${visible.length} produk ditampilkan`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="label-eyebrow">Produk</TableHead>
                                    <TableHead className="label-eyebrow">Kategori</TableHead>
                                    <TableHead className="label-eyebrow">Satuan</TableHead>
                                    <TableHead className="label-eyebrow text-right">Harga Dasar</TableHead>
                                    <TableHead className="label-eyebrow text-right">HPP Rata-rata</TableHead>
                                    <TableHead className="label-eyebrow text-right">Stok</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <EmptyRow
                                        colSpan={6}
                                        icon={<Package />}
                                        message={search ? `Tidak ada produk cocok dengan "${search}".` : 'Belum ada produk.'}
                                    />
                                )}
                                {pagination.paged.map((p) => (
                                    <TableRow
                                        key={p.id}
                                        className="cursor-pointer"
                                        onClick={() => setDetail(p)}
                                    >
                                        <TableCell>
                                            <div className="flex items-center gap-2.5">
                                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted">
                                                    {p.isWeighted ? (
                                                        <Scale className="h-3.5 w-3.5 text-muted-foreground" />
                                                    ) : (
                                                        <Package className="h-3.5 w-3.5 text-muted-foreground" />
                                                    )}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {p.name}
                                                        {!p.active && (
                                                            <span className="ml-1.5 text-2xs text-muted-foreground">
                                                                (nonaktif)
                                                            </span>
                                                        )}
                                                    </p>
                                                    <p className="font-mono text-2xs text-muted-foreground">{p.sku}</p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{p.category}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {p.uoms.map((u) => (
                                                    <span
                                                        key={u.name}
                                                        className={cn(
                                                            'rounded px-1.5 py-0.5 font-mono text-2xs',
                                                            u.type === 'BASE'
                                                                ? 'bg-primary/10 text-primary'
                                                                : 'bg-muted text-muted-foreground',
                                                        )}
                                                    >
                                                        {u.name}
                                                        {u.factor > 1 && `·${u.factor}`}
                                                    </span>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {formatRupiah(p.uoms[0].price)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-muted-foreground">
                                            {formatRupiah(p.avgCost)}
                                        </TableCell>
                                        <TableCell className={cn('text-right font-mono font-medium', stockTone(p))}>
                                            {formatQty(p.stock)}{' '}
                                            <span className="text-2xs text-muted-foreground">{p.baseUom}</span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <TablePagination {...pagination} />
                </Panel>
            </div>

            {/* ------------------------------------------------ detail konversi */}
            <Dialog open={!!detail} onOpenChange={(open) => !open && setDetail(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{detail?.name}</DialogTitle>
                    </DialogHeader>

                    {detail && (
                        <div className="space-y-4">
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="label-eyebrow">SKU</dt>
                                    <dd className="font-mono">{detail.sku}</dd>
                                </div>
                                <div>
                                    <dt className="label-eyebrow">Barcode</dt>
                                    <dd className="font-mono">{detail.barcode}</dd>
                                </div>
                                <div>
                                    <dt className="label-eyebrow">Satuan dasar</dt>
                                    <dd className="font-medium">{detail.baseUom}</dd>
                                </div>
                                <div>
                                    <dt className="label-eyebrow">Jenis qty</dt>
                                    <dd>{detail.isWeighted ? 'Desimal (timbangan)' : 'Bilangan bulat'}</dd>
                                </div>
                            </dl>

                            <div>
                                <p className="label-eyebrow mb-2">Konversi satuan</p>
                                <div className="overflow-hidden rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="label-eyebrow">Satuan</TableHead>
                                                <TableHead className="label-eyebrow text-right">Isi</TableHead>
                                                <TableHead className="label-eyebrow text-right">Harga Jual</TableHead>
                                                <TableHead className="label-eyebrow text-right">Per {detail.baseUom}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {detail.uoms.map((u) => (
                                                <TableRow key={u.name}>
                                                    <TableCell className="font-medium">
                                                        {u.name}
                                                        {u.type === 'BASE' && (
                                                            <span className="ml-1.5 rounded bg-primary/10 px-1 py-0.5 text-2xs text-primary">
                                                                dasar
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        {formatQty(u.factor)} {detail.baseUom}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        {formatRupiah(u.price)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono text-muted-foreground">
                                                        {formatRupiah(u.price / u.factor)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>

                            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                Faktor konversi tidak dapat diubah bila satuan sudah dipakai transaksi — hanya bisa
                                dinonaktifkan.
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
