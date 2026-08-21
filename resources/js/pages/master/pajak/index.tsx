import { Head, useForm, usePage } from '@inertiajs/react';
import { Percent, Plus } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, DeleteButton, EditButton, Field, FlashMessage, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Pajak', href: '/master/pajak' },
];

interface Pajak {
    id: number;
    name: string;
    rate: number;
    active: boolean;
}

export default function PajakIndex() {
    const { taxes, filters, auth } = usePage().props as unknown as {
        taxes: Paginated<Pajak>;
        filters: { q: string };
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Pajak | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({ tax_name: '', tax_rate: 0, active: true as boolean });

    const buka = (p: Pajak | null) => {
        setSedangDiubah(p);
        form.setData({ tax_name: p?.name ?? '', tax_rate: p?.rate ?? 0, active: p?.active ?? true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/pajak/${sedangDiubah.id}`, opsi) : form.post('/master/pajak', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Pajak" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Pajak"
                    subtitle="Tarif dipakai saat menghitung PPN pada penjualan dan faktur pemasok."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah pajak
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar pajak"
                    description={`${taxes.total} tarif terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/pajak" initial={filters.q} placeholder="Cari pajak..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-28 text-right">Tarif</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {taxes.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 4 : 3} icon={<Percent />} message="Belum ada tarif pajak." />
                            )}

                            {taxes.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell className="font-medium">{p.name}</TableCell>
                                    <TableCell className="text-right font-mono text-sm tabular">
                                        {p.rate.toFixed(2).replace('.', ',')}%
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={p.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(p)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/pajak/${p.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={taxes} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah pajak' : 'Tambah pajak'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama pajak" required error={form.errors.tax_name}>
                            <Input value={form.data.tax_name} onChange={(e) => form.setData('tax_name', e.target.value)} className="h-8" placeholder="PPN 11%" />
                        </Field>
                        <Field label="Tarif (%)" required error={form.errors.tax_rate}>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                value={form.data.tax_rate}
                                onChange={(e) => form.setData('tax_rate', Number(e.target.value))}
                                className="h-8 font-mono tabular"
                            />
                        </Field>
                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} />
                            <span className="text-xs font-medium">Aktif</span>
                        </label>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setDialogTerbuka(false)}>
                            Batal
                        </Button>
                        <Button size="sm" onClick={simpan} disabled={form.processing}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
