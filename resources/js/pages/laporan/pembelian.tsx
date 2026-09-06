import { KartuAngka, PemilihPeriode, TanpaPeriode, type PilihanPeriode } from '@/components/laporan-ui';
import { EmptyRow, PageHeader, Panel, formatDate, formatRupiah } from '@/components/pos-ui';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { PackagePlus, Truck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pembelian', href: '#' },
    { title: 'Laporan Pembelian', href: '/laporan/pembelian' },
];

interface Ringkasan {
    receiptCount: number;
    receiptValue: number;
    invoiceCount: number;
    invoiceValue: number;
    returnCount: number;
    returnValue: number;
    uninvoicedCount: number;
    uninvoicedValue: number;
    payableCount: number;
    payableOutstanding: number;
}

export default function LaporanPembelian() {
    const { periods, period, summary, suppliers, uninvoiced } = usePage().props as unknown as {
        periods: PilihanPeriode[];
        period: { id: number; code: string; status: string; startDate: string; endDate: string; branch: string | null } | null;
        summary: Ringkasan | null;
        suppliers: { name: string; receiptCount: number; receiptValue: number; returnValue: number; outstanding: number }[];
        uninvoiced: { number: string; date: string; supplier: string | null; value: number }[];
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laporan Pembelian" />

            <div className="flex flex-col gap-4 p-4 md:p-5">
                <PageHeader
                    title="Laporan Pembelian"
                    subtitle="Penerimaan dan faktur dilaporkan terpisah — yang menambah stok adalah penerimaan, yang menerbitkan utang adalah faktur."
                />

                <PemilihPeriode routeUrl="/laporan/pembelian" periods={periods} active={period} />

                {summary === null && <TanpaPeriode />}

                {summary !== null && (
                    <>
                        <KartuAngka
                            items={[
                                { label: 'Penerimaan barang', value: formatRupiah(summary.receiptValue) },
                                { label: 'Faktur pemasok', value: formatRupiah(summary.invoiceValue) },
                                { label: 'Retur pembelian', value: formatRupiah(summary.returnValue) },
                                {
                                    label: 'Utang belum lunas',
                                    value: formatRupiah(summary.payableOutstanding),
                                    warn: summary.payableOutstanding > 0,
                                },
                            ]}
                        />

                        {summary.uninvoicedCount > 0 && (
                            <p className="bg-warning/10 rounded-md px-3 py-2 text-xs">
                                {summary.uninvoicedCount} penerimaan senilai {formatRupiah(summary.uninvoicedValue)} belum difakturkan.
                                Nilai persediaannya masih memakai harga sementara, dan periode tidak dapat ditutup sebelum diselesaikan
                                atau disetujui pemilik.
                            </p>
                        )}

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Panel title="Ringkasan Dokumen" description="Yang berstatus sah saja">
                                <dl className="space-y-1 text-xs">
                                    {[
                                        ['Penerimaan barang', summary.receiptCount, summary.receiptValue],
                                        ['Faktur pemasok', summary.invoiceCount, summary.invoiceValue],
                                        ['Retur pembelian', summary.returnCount, summary.returnValue],
                                        ['Belum difakturkan', summary.uninvoicedCount, summary.uninvoicedValue],
                                        ['Utang belum lunas', summary.payableCount, summary.payableOutstanding],
                                    ].map(([label, count, value]) => (
                                        <div key={label as string} className="flex items-baseline justify-between gap-2">
                                            <dt className="text-muted-foreground">
                                                {label}
                                                <span className="text-2xs ml-1.5 font-mono">({count as number})</span>
                                            </dt>
                                            <dd className="tabular font-mono">{formatRupiah(value as number)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Panel>

                            <Panel title="Per Pemasok" description={`${suppliers.length} pemasok bertransaksi`} className="lg:col-span-2" bodyClassName="p-0">
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Pemasok</TableHead>
                                                <TableHead className="w-20 text-right">Terima</TableHead>
                                                <TableHead className="w-32 text-right">Nilai</TableHead>
                                                <TableHead className="w-32 text-right">Retur</TableHead>
                                                <TableHead className="w-32 text-right">Utang</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {suppliers.length === 0 && (
                                                <EmptyRow colSpan={5} icon={<Truck />} message="Belum ada penerimaan pada periode ini." />
                                            )}
                                            {suppliers.map((s) => (
                                                <TableRow key={s.name}>
                                                    <TableCell className="text-xs">{s.name}</TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">{s.receiptCount}</TableCell>
                                                    <TableCell className="tabular text-right font-mono text-xs">
                                                        {formatRupiah(s.receiptValue)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground tabular text-right font-mono text-xs">
                                                        {formatRupiah(s.returnValue)}
                                                    </TableCell>
                                                    <TableCell
                                                        className={cn(
                                                            'tabular text-right font-mono text-xs font-medium',
                                                            s.outstanding > 0 && 'text-warning',
                                                        )}
                                                    >
                                                        {formatRupiah(s.outstanding)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </Panel>
                        </div>

                        <Panel
                            title="Penerimaan Belum Difakturkan"
                            description={uninvoiced.length > 0 ? `${uninvoiced.length} dokumen` : 'Tidak ada'}
                            bodyClassName="p-0"
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-52">Nomor</TableHead>
                                        <TableHead className="w-28">Tanggal</TableHead>
                                        <TableHead>Pemasok</TableHead>
                                        <TableHead className="w-36 text-right">Nilai</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {uninvoiced.length === 0 && (
                                        <EmptyRow colSpan={4} icon={<PackagePlus />} message="Seluruh penerimaan sudah difakturkan." />
                                    )}
                                    {uninvoiced.map((r) => (
                                        <TableRow key={r.number}>
                                            <TableCell className="tabular font-mono text-xs">{r.number}</TableCell>
                                            <TableCell className="text-muted-foreground tabular text-xs">{formatDate(r.date)}</TableCell>
                                            <TableCell className="text-xs">{r.supplier ?? '—'}</TableCell>
                                            <TableCell className="tabular text-right font-mono text-xs">{formatRupiah(r.value)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Panel>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
