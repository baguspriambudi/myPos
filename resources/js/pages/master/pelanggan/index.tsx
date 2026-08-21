import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, UserRound } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Pelanggan', href: '/master/pelanggan' },
];

interface Pelanggan {
    id: number;
    name: string;
    phone: string | null;
    creditLimit: number;
    branchId: number | null;
    branchName: string | null;
    priceListId: number | null;
    priceListName: string | null;
    active: boolean;
}

export default function PelangganIndex() {
    const { customers, filters, branches, priceLists, auth } = usePage().props as unknown as {
        customers: Paginated<Pelanggan>;
        filters: { q: string };
        branches: { id: number; name: string }[];
        priceLists: { id: number; name: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Pelanggan | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        customer_name: '',
        phone: '',
        credit_limit: 0,
        branch_id: '' as string | number,
        price_list_id: '' as string | number,
        active: true as boolean,
    });

    const buka = (p: Pelanggan | null) => {
        setSedangDiubah(p);
        form.setData({
            customer_name: p?.name ?? '',
            phone: p?.phone ?? '',
            credit_limit: p?.creditLimit ?? 0,
            branch_id: p?.branchId ?? '',
            price_list_id: p?.priceListId ?? '',
            active: p?.active ?? true,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/pelanggan/${sedangDiubah.id}`, opsi) : form.post('/master/pelanggan', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Pelanggan" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Pelanggan"
                    subtitle="Pelanggan dapat diberi daftar harga sendiri — inilah cara memberi harga grosir tanpa memengaruhi pembeli eceran."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah pelanggan
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar pelanggan"
                    description={`${customers.total} pelanggan terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/pelanggan" initial={filters.q} placeholder="Cari nama atau telepon..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-36">Telepon</TableHead>
                                <TableHead className="w-40">Daftar harga</TableHead>
                                <TableHead className="w-36 text-right">Batas piutang</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 6 : 5} icon={<UserRound />} message="Belum ada pelanggan." />
                            )}

                            {customers.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell>
                                        <p className="font-medium">{p.name}</p>
                                        {p.branchName && <p className="text-2xs text-muted-foreground">{p.branchName}</p>}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs tabular">{p.phone ?? '—'}</TableCell>
                                    <TableCell>
                                        {p.priceListName ? <Chip tone="accent">{p.priceListName}</Chip> : <span className="text-xs text-muted-foreground">Harga umum</span>}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs tabular">
                                        {p.creditLimit > 0 ? formatRupiah(p.creditLimit) : <span className="text-muted-foreground">Tunai</span>}
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={p.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(p)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/pelanggan/${p.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={customers} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah pelanggan' : 'Tambah pelanggan'}</DialogTitle>
                        <DialogDescription>Batas piutang 0 berarti pelanggan ini hanya boleh transaksi tunai.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Nama pelanggan" required error={form.errors.customer_name}>
                                <Input value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} className="h-8" />
                            </Field>
                            <Field label="Telepon" error={form.errors.phone}>
                                <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="h-8 font-mono" />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Daftar harga" error={form.errors.price_list_id} hint="Menang atas daftar harga lokasi">
                                <NativeSelect
                                    value={form.data.price_list_id}
                                    onChange={(v) => form.setData('price_list_id', v)}
                                    placeholder="Ikut harga umum"
                                    options={priceLists.map((d) => ({ value: d.id, label: d.name }))}
                                />
                            </Field>
                            <Field label="Batas piutang" required error={form.errors.credit_limit}>
                                <Input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.credit_limit}
                                    onChange={(e) => form.setData('credit_limit', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                />
                            </Field>
                        </div>

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
