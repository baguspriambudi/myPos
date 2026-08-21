import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
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
    { title: 'Pemasok', href: '/master/pemasok' },
];

interface Pemasok {
    id: number;
    name: string;
    phone: string | null;
    branchId: number | null;
    branchName: string | null;
    active: boolean;
}

export default function PemasokIndex() {
    const { suppliers, filters, branches, auth } = usePage().props as unknown as {
        suppliers: Paginated<Pemasok>;
        filters: { q: string };
        branches: { id: number; name: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Pemasok | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({ supplier_name: '', phone: '', branch_id: '' as string | number, active: true as boolean });

    const buka = (s: Pemasok | null) => {
        setSedangDiubah(s);
        form.setData({
            supplier_name: s?.name ?? '',
            phone: s?.phone ?? '',
            branch_id: s?.branchId ?? '',
            active: s?.active ?? true,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/pemasok/${sedangDiubah.id}`, opsi) : form.post('/master/pemasok', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Pemasok" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Pemasok"
                    subtitle="Dipakai pada penerimaan barang dan faktur pemasok."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah pemasok
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar pemasok"
                    description={`${suppliers.total} pemasok terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/pemasok" initial={filters.q} placeholder="Cari nama atau telepon..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-40">Telepon</TableHead>
                                <TableHead className="w-40">Lokasi</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {suppliers.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 5 : 4} icon={<Truck />} message="Belum ada pemasok." />
                            )}

                            {suppliers.data.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell className="font-medium">{s.name}</TableCell>
                                    <TableCell className="font-mono text-xs tabular">{s.phone ?? '—'}</TableCell>
                                    <TableCell className="text-xs text-muted-foreground">{s.branchName ?? 'Semua lokasi'}</TableCell>
                                    <TableCell>
                                        <ActiveBadge active={s.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(s)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/pemasok/${s.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={suppliers} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah pemasok' : 'Tambah pemasok'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama pemasok" required error={form.errors.supplier_name}>
                            <Input value={form.data.supplier_name} onChange={(e) => form.setData('supplier_name', e.target.value)} className="h-8" />
                        </Field>
                        <Field label="Telepon" error={form.errors.phone}>
                            <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="h-8 font-mono" />
                        </Field>
                        <Field label="Lokasi" error={form.errors.branch_id} hint="Kosongkan bila berlaku di semua lokasi">
                            <NativeSelect
                                value={form.data.branch_id}
                                onChange={(v) => form.setData('branch_id', v)}
                                placeholder="Semua lokasi"
                                options={branches.map((b) => ({ value: b.id, label: b.name }))}
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
