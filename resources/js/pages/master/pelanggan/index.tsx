import {
    ActiveBadge,
    Chip,
    DeleteButton,
    EditButton,
    Field,
    FlashMessage,
    NativeSelect,
    ServerPagination,
    ServerSearch,
    type Paginated,
} from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatRupiah } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, UserRound } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Pelanggan', href: '/master/pelanggan' },
];

interface Pelanggan {
    id: number;
    name: string;
    phone: string | null;
    isMember: boolean;
    /** Pelanggan tunai bawaan: tujuan seluruh penjualan yang tidak menyebut pelanggan. */
    isDefault: boolean;
    creditLimit: number;
    branchId: number | null;
    branchName: string | null;
    active: boolean;
}

export default function PelangganIndex() {
    const { customers, filters, branches, daftarHargaMember, auth } = usePage().props as unknown as {
        customers: Paginated<Pelanggan>;
        filters: { q: string };
        branches: { id: number; name: string }[];
        /** Nama daftar harga bertanda member — keterangan saja, tidak ada yang dipilih di sini. */
        daftarHargaMember: string[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Pelanggan | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        customer_name: '',
        phone: '',
        is_member: true as boolean,
        is_default: false as boolean,
        credit_limit: 0,
        branch_id: '' as string | number,
        active: true as boolean,
    });

    // Pelanggan tunai bawaan hanya punya nama: sisanya dikunci karena ia
    // mewakili pembeli yang tidak disebut namanya.
    const bawaan = form.data.is_default;

    const buka = (p: Pelanggan | null) => {
        setSedangDiubah(p);
        form.setData({
            customer_name: p?.name ?? '',
            phone: p?.phone ?? '',
            // Yang didaftarkan di layar ini pada dasarnya memang member.
            is_member: p?.isMember ?? true,
            is_default: p?.isDefault ?? false,
            credit_limit: p?.creditLimit ?? 0,
            branch_id: p?.branchId ?? '',
            active: p?.active ?? true,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/pelanggan/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/pelanggan', opsi);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Pelanggan" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Pelanggan"
                    subtitle="Penjualan eceran tercatat ke pelanggan tunai bawaan tanpa perlu dipilih kasir. Yang didaftarkan di sini adalah member — member otomatis memakai daftar harga bertanda khusus member."
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
                    action={
                        <ServerSearch routeUrl="/master/pelanggan" initial={filters.q} placeholder="Cari nama atau telepon..." className="w-56" />
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-36">Telepon</TableHead>
                                <TableHead className="w-36 text-right">Batas piutang</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 5 : 4} icon={<UserRound />} message="Belum ada pelanggan." />
                            )}

                            {customers.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-medium">{p.name}</span>
                                            {p.isDefault && <Chip tone="accent">Tunai</Chip>}
                                            {p.isMember && <Chip tone="warning">Member</Chip>}
                                        </div>
                                        {p.branchName && <p className="text-2xs text-muted-foreground">{p.branchName}</p>}
                                    </TableCell>
                                    <TableCell className="tabular font-mono text-xs">{p.phone ?? '—'}</TableCell>
                                    <TableCell className="tabular text-right font-mono text-xs">
                                        {p.creditLimit > 0 ? formatRupiah(p.creditLimit) : <span className="text-muted-foreground">Tunai</span>}
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={p.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(p)} />
                                                <DeleteButton
                                                    onConfirm={() => form.delete(`/master/pelanggan/${p.id}`, { preserveScroll: true })}
                                                    disabled={p.isDefault}
                                                    disabledReason="Pelanggan tunai bawaan tidak dapat dihapus."
                                                />
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
                        <DialogDescription>
                            Batas piutang 0 berarti pelanggan ini hanya boleh transaksi tunai. Harga tidak diatur di sini — cukup tandai member,
                            selebihnya ditentukan daftar harga.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Nama pelanggan" required error={form.errors.customer_name}>
                                <Input
                                    value={form.data.customer_name}
                                    onChange={(e) => form.setData('customer_name', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                            <Field label="Telepon" error={form.errors.phone}>
                                <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="h-8 font-mono" />
                            </Field>
                        </div>

                        <div className="bg-muted/30 space-y-2 rounded-md border p-3">
                            <label className="flex items-start gap-2">
                                <Checkbox
                                    checked={form.data.is_member}
                                    onCheckedChange={(v) => form.setData('is_member', v === true)}
                                    disabled={bawaan}
                                    className="mt-0.5"
                                />
                                <span className="text-xs">
                                    <span className="font-medium">Member</span>
                                    <span className="text-2xs text-muted-foreground block">
                                        {daftarHargaMember.length > 0
                                            ? `Otomatis memakai daftar harga khusus member: ${daftarHargaMember.join(', ')}.`
                                            : 'Otomatis memakai daftar harga bertanda khusus member. Belum ada satu pun, jadi untuk sekarang harganya sama dengan pelanggan tunai.'}
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-start gap-2">
                                <Checkbox
                                    checked={form.data.is_default}
                                    onCheckedChange={(v) => form.setData('is_default', v === true)}
                                    className="mt-0.5"
                                />
                                <span className="text-xs">
                                    <span className="font-medium">Pelanggan tunai bawaan</span>
                                    <span className="text-2xs text-muted-foreground block">
                                        Seluruh penjualan yang tidak menyebut pelanggan tercatat ke sini, jadi kasir tidak perlu memilih apa pun.
                                        Hanya satu per usaha; menandai pelanggan lain akan memindahkannya.
                                    </span>
                                </span>
                            </label>
                            {form.errors.is_default && <p className="text-2xs text-destructive font-medium">{form.errors.is_default}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Batas piutang" required error={form.errors.credit_limit}>
                                <Input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={bawaan ? 0 : form.data.credit_limit}
                                    onChange={(e) => form.setData('credit_limit', Number(e.target.value))}
                                    className="tabular h-8 font-mono"
                                    disabled={bawaan}
                                />
                            </Field>
                            <Field label="Lokasi" error={form.errors.branch_id} hint="Kosongkan bila berlaku di semua lokasi">
                                <NativeSelect
                                    value={bawaan ? '' : form.data.branch_id}
                                    onChange={(v) => form.setData('branch_id', v)}
                                    placeholder="Semua lokasi"
                                    options={bawaan ? [] : branches.map((b) => ({ value: b.id, label: b.name }))}
                                />
                            </Field>
                        </div>

                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={bawaan ? true : form.data.active}
                                onCheckedChange={(v) => form.setData('active', v === true)}
                                disabled={bawaan}
                            />
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
