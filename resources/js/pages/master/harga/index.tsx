import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel, formatDate } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Daftar Harga', href: '/master/harga' },
];

interface DaftarHarga {
    id: number;
    code: string;
    name: string;
    branchId: number | null;
    branchName: string | null;
    isDefault: boolean;
    customerOnly: boolean;
    priority: number;
    startDate: string | null;
    endDate: string | null;
    active: boolean;
    itemsCount: number;
}

export default function HargaIndex() {
    const { priceLists, filters, branches, auth } = usePage().props as unknown as {
        priceLists: Paginated<DaftarHarga>;
        filters: { q: string };
        branches: { id: number; name: string; code: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<DaftarHarga | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        price_list_code: '',
        price_list_name: '',
        branch_id: '' as string | number,
        is_default: false as boolean,
        customer_only: false as boolean,
        priority: 0,
        start_date: '',
        end_date: '',
        active: true as boolean,
    });

    const bukaTambah = () => {
        setSedangDiubah(null);
        form.setData({
            price_list_code: '',
            price_list_name: '',
            branch_id: '',
            is_default: false,
            customer_only: false,
            priority: 0,
            start_date: '',
            end_date: '',
            active: true,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (d: DaftarHarga) => {
        setSedangDiubah(d);
        form.setData({
            price_list_code: d.code,
            price_list_name: d.name,
            branch_id: d.branchId ?? '',
            is_default: d.isDefault,
            customer_only: d.customerOnly,
            priority: d.priority,
            start_date: d.startDate ?? '',
            end_date: d.endDate ?? '',
            active: d.active,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/harga/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/harga', opsi);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Daftar Harga" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Daftar Harga"
                    subtitle="Harga jual melekat pada satuan dan boleh bertingkat menurut kuantitas. Yang tidak diatur di sini memakai harga standar produk."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={bukaTambah}>
                                <Plus className="h-3.5 w-3.5" /> Tambah daftar harga
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <div className="rounded-md border border-dashed bg-muted/30 px-3 py-2.5 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">Urutan pemilihan harga:</span> daftar harga pelanggan →
                    daftar harga lokasi → daftar harga umum → daftar bawaan → harga standar produk. Daftar bertanda{' '}
                    <span className="font-medium text-foreground">khusus pelanggan</span> hanya dipakai bila pelanggannya
                    ditugaskan ke daftar itu.
                </div>

                <Panel
                    title="Daftar harga"
                    description={`${priceLists.total} daftar terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/harga" initial={filters.q} placeholder="Cari nama atau kode..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-28">Kode</TableHead>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-40">Berlaku untuk</TableHead>
                                <TableHead className="w-44">Masa berlaku</TableHead>
                                <TableHead className="w-20 text-right">Prioritas</TableHead>
                                <TableHead className="w-20 text-right">Baris</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                <TableHead className="w-40 text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {priceLists.data.length === 0 && (
                                <EmptyRow colSpan={8} icon={<Tags />} message="Belum ada daftar harga." />
                            )}

                            {priceLists.data.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell className="font-mono text-xs font-semibold tabular">{d.code}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-medium">{d.name}</span>
                                            {d.isDefault && <Chip tone="accent">Bawaan</Chip>}
                                            {d.customerOnly && <Chip tone="warning">Khusus pelanggan</Chip>}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {d.branchName ?? 'Semua lokasi'}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground tabular">
                                        {d.startDate || d.endDate
                                            ? `${d.startDate ? formatDate(d.startDate) : '…'} – ${d.endDate ? formatDate(d.endDate) : '…'}`
                                            : 'Tanpa batas'}
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs tabular">{d.priority}</TableCell>
                                    <TableCell className="text-right font-mono text-xs tabular">{d.itemsCount}</TableCell>
                                    <TableCell>
                                        <ActiveBadge active={d.active} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button asChild variant="ghost" size="sm" className="h-7 text-xs">
                                                <Link href={`/master/harga/${d.id}`}>Atur harga</Link>
                                            </Button>
                                            {bolehUbah && (
                                                <>
                                                    <EditButton onClick={() => bukaUbah(d)} />
                                                    <DeleteButton onConfirm={() => form.delete(`/master/harga/${d.id}`, { preserveScroll: true })} />
                                                </>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={priceLists} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah daftar harga' : 'Tambah daftar harga'}</DialogTitle>
                        <DialogDescription>
                            Isi tingkat harganya setelah daftar ini dibuat, lewat tombol Atur harga.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Kode" required error={form.errors.price_list_code}>
                                <Input
                                    value={form.data.price_list_code}
                                    onChange={(e) => form.setData('price_list_code', e.target.value.toUpperCase())}
                                    className="h-8 font-mono uppercase"
                                    maxLength={20}
                                    placeholder="PL-UMUM"
                                />
                            </Field>
                            <Field label="Nama" required error={form.errors.price_list_name}>
                                <Input
                                    value={form.data.price_list_name}
                                    onChange={(e) => form.setData('price_list_name', e.target.value)}
                                    className="h-8"
                                    placeholder="Harga Umum (Eceran)"
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Lokasi" error={form.errors.branch_id} hint="Kosongkan untuk seluruh lokasi">
                                <NativeSelect
                                    value={form.data.branch_id}
                                    onChange={(v) => form.setData('branch_id', v)}
                                    placeholder="Semua lokasi"
                                    options={branches.map((b) => ({ value: b.id, label: `${b.code} — ${b.name}` }))}
                                />
                            </Field>
                            <Field label="Prioritas" required error={form.errors.priority} hint="Makin besar makin diutamakan">
                                <Input
                                    type="number"
                                    value={form.data.priority}
                                    onChange={(e) => form.setData('priority', Number(e.target.value))}
                                    className="h-8 font-mono tabular"
                                    min={0}
                                    max={999}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Mulai berlaku" error={form.errors.start_date}>
                                <Input
                                    type="date"
                                    value={form.data.start_date}
                                    onChange={(e) => form.setData('start_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                            <Field label="Berlaku sampai" error={form.errors.end_date}>
                                <Input
                                    type="date"
                                    value={form.data.end_date}
                                    onChange={(e) => form.setData('end_date', e.target.value)}
                                    className="h-8"
                                />
                            </Field>
                        </div>

                        <div className="space-y-2 rounded-md border bg-muted/30 p-3">
                            <label className="flex items-start gap-2">
                                <Checkbox
                                    checked={form.data.customer_only}
                                    onCheckedChange={(v) => form.setData('customer_only', v === true)}
                                    className="mt-0.5"
                                />
                                <span className="text-xs">
                                    <span className="font-medium">Khusus pelanggan tertentu</span>
                                    <span className="block text-2xs text-muted-foreground">
                                        Hanya berlaku bagi pelanggan yang ditugaskan ke daftar ini. Wajib dicentang untuk
                                        harga grosir — kalau tidak, pembeli eceran ikut mendapatkannya.
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
                                    <span className="font-medium">Jadikan daftar bawaan</span>
                                    <span className="block text-2xs text-muted-foreground">
                                        Dipakai bila tidak ada daftar lain yang cocok. Hanya satu daftar bawaan per usaha.
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.active}
                                    onCheckedChange={(v) => form.setData('active', v === true)}
                                />
                                <span className="text-xs font-medium">Aktif</span>
                            </label>
                        </div>
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
