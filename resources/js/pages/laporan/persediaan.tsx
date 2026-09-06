import { ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatQty, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persediaan', href: '#' },
    { title: 'Laporan Persediaan', href: '/laporan/persediaan' },
];

interface Baris {
    name: string;
    sku: string;
    category: string;
    uom: string;
    qty: number;
    avgCost: number | null;
    value: number | null;
}

export default function LaporanPersediaan() {
    const { rows, summary, filters, canSeeCost, branch } = usePage().props as unknown as {
        rows: Paginated<Baris>;
        summary: { productCount: number; emptyCount: number; negativeCount: number; totalValue: number | null };
        filters: { q: string };
        canSeeCost: boolean;
        branch: { id: number; name: string } | null;
    };

    const kartu = [
        { label: 'Produk bersaldo', value: String(summary.productCount) },
        ...(canSeeCost ? [{ label: 'Nilai persediaan', value: formatRupiah(summary.totalValue ?? 0) }] : []),
        { label: 'Habis', value: String(summary.emptyCount) },
        { label: 'Bersaldo negatif', value: String(summary.negativeCount), warn: summary.negativeCount > 0 },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laporan Persediaan" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Laporan Persediaan"
                    subtitle={`Saldo dan nilai persediaan di ${branch?.name ?? 'lokasi aktif'}, dalam satuan dasar.`}
                    action={
                        <Button size="sm" variant="outline" asChild>
                            <Link href="/stok">Kartu stok</Link>
                        </Button>
                    }
                />

                <div className={cn('grid gap-3 sm:grid-cols-3', canSeeCost && 'lg:grid-cols-4')}>
                    {kartu.map((k) => (
                        <div key={k.label} className="bg-card rounded-lg border px-4 py-3">
                            <p className="label-eyebrow">{k.label}</p>
                            <p className={cn('tabular mt-1 font-mono text-xl font-semibold', k.warn && 'text-destructive')}>{k.value}</p>
                        </div>
                    ))}
                </div>

                {summary.negativeCount > 0 && (
                    <p className="bg-warning/10 rounded-md px-3 py-2 text-xs">
                        Ada {summary.negativeCount} produk bersaldo negatif. Periode tidak dapat ditutup sebelum diselesaikan lewat
                        penyesuaian stok atau opname, kecuali pemilik menyetujuinya secara tertulis.
                    </p>
                )}

                <ServerSearch routeUrl="/laporan/persediaan" initial={filters.q} placeholder="Cari nama produk atau SKU..." />

                <Panel title="Saldo per Produk" description={`${rows.total} produk`} bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Produk</TableHead>
                                    <TableHead className="w-36">Kategori</TableHead>
                                    <TableHead className="w-28 text-right">Saldo</TableHead>
                                    <TableHead className="w-20">Satuan</TableHead>
                                    {canSeeCost && <TableHead className="w-32 text-right">HPP Rata-rata</TableHead>}
                                    {canSeeCost && <TableHead className="w-32 text-right">Nilai</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.data.length === 0 && (
                                    <EmptyRow colSpan={canSeeCost ? 6 : 4} icon={<Boxes />} message="Tidak ada produk yang cocok." />
                                )}

                                {rows.data.map((r) => (
                                    <TableRow key={r.sku}>
                                        <TableCell>
                                            <p className="text-xs font-medium">{r.name}</p>
                                            <p className="text-2xs text-muted-foreground font-mono">{r.sku}</p>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-2xs">{r.category}</TableCell>
                                        <TableCell
                                            className={cn(
                                                'tabular text-right font-mono text-xs font-medium',
                                                r.qty < 0 && 'text-destructive',
                                                r.qty === 0 && 'text-muted-foreground',
                                            )}
                                        >
                                            {formatQty(r.qty)}
                                        </TableCell>
                                        <TableCell className="text-2xs text-muted-foreground">{r.uom}</TableCell>
                                        {canSeeCost && (
                                            <TableCell className="text-muted-foreground tabular text-right font-mono text-xs">
                                                {formatRupiah(r.avgCost ?? 0)}
                                            </TableCell>
                                        )}
                                        {canSeeCost && (
                                            <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(r.value ?? 0)}</TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ServerPagination page={rows} />
                </Panel>
            </div>
        </AppLayout>
    );
}
