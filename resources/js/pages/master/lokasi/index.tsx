import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, Plus, Store, Warehouse } from 'lucide-react';
import { useState } from 'react';
import { ActiveBadge, Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Lokasi', href: '/master/lokasi' },
];

interface Lokasi {
    id: number;
    code: string;
    name: string;
    type: string;
    typeLabel: string;
    canSell: boolean;
    address: string | null;
    active: boolean;
    usersCount: number;
}

interface TipeLokasi {
    value: string;
    label: string;
    hint: string;
}

const IKON: Record<string, typeof Store> = {
    STORE: Store,
    DEPO: Building2,
    WAREHOUSE: Warehouse,
};

export default function LokasiIndex() {
    const { branches, filters, types, auth } = usePage().props as unknown as {
        branches: Paginated<Lokasi>;
        filters: { q: string };
        types: TipeLokasi[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Lokasi | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        branch_code: '',
        branch_name: '',
        branch_type: 'STORE',
        // Anotasi eksplisit: tanpa ini TypeScript menyimpulkan tipe literal
        // `true`, sehingga setData(false) ditolak.
        can_sell: true as boolean,
        address: '',
        active: true as boolean,
    });

    const bukaTambah = () => {
        setSedangDiubah(null);
        form.setData({ branch_code: '', branch_name: '', branch_type: 'STORE', can_sell: true, address: '', active: true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (lokasi: Lokasi) => {
        setSedangDiubah(lokasi);
        form.setData({
            branch_code: lokasi.code,
            branch_name: lokasi.name,
            branch_type: lokasi.type,
            can_sell: lokasi.canSell,
            address: lokasi.address ?? '',
            active: lokasi.active,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    /**
     * Mengganti tipe ikut menyesuaikan izin menjual, karena itulah yang
     * dimaksud 99% waktu. Nilainya tetap bisa diubah manual sesudahnya untuk
     * kasus gudang yang sesekali melayani grosir.
     */
    const gantiTipe = (tipe: string) => {
        form.setData((data) => ({ ...data, branch_type: tipe, can_sell: tipe !== 'WAREHOUSE' }));
    };

    const simpan = () => {
        const opsi = {
            preserveScroll: true,
            onSuccess: () => setDialogTerbuka(false),
        };

        if (sedangDiubah) {
            form.put(`/master/lokasi/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/lokasi', opsi);
        }
    };

    const hapus = (lokasi: Lokasi) => {
        form.delete(`/master/lokasi/${lokasi.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Lokasi" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Lokasi"
                    subtitle="Toko, depo, dan gudang. Gudang menyimpan dan memindahkan barang tanpa melayani penjualan."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={bukaTambah}>
                                <Plus className="h-3.5 w-3.5" /> Tambah lokasi
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar lokasi"
                    description={`${branches.total} lokasi terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/lokasi" initial={filters.q} placeholder="Cari nama atau kode..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Kode</TableHead>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-28">Tipe</TableHead>
                                <TableHead className="w-32">Penjualan</TableHead>
                                <TableHead className="w-24">Pengguna</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branches.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 7 : 6} icon={<Store />} message="Belum ada lokasi." />
                            )}

                            {branches.data.map((lokasi) => {
                                const Ikon = IKON[lokasi.type] ?? Store;

                                return (
                                    <TableRow key={lokasi.id}>
                                        <TableCell className="font-mono text-xs font-semibold tabular">{lokasi.code}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Ikon className="h-3.5 w-3.5 text-muted-foreground" />
                                                <div>
                                                    <p className="font-medium">{lokasi.name}</p>
                                                    {lokasi.address && (
                                                        <p className="text-2xs text-muted-foreground">{lokasi.address}</p>
                                                    )}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Chip tone={lokasi.type === 'WAREHOUSE' ? 'warning' : 'neutral'}>{lokasi.typeLabel}</Chip>
                                        </TableCell>
                                        <TableCell>
                                            {lokasi.canSell ? (
                                                <Chip tone="accent">Boleh menjual</Chip>
                                            ) : (
                                                <Chip>Tidak menjual</Chip>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs tabular">{lokasi.usersCount}</TableCell>
                                        <TableCell>
                                            <ActiveBadge active={lokasi.active} />
                                        </TableCell>
                                        {bolehUbah && (
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditButton onClick={() => bukaUbah(lokasi)} />
                                                    <DeleteButton onConfirm={() => hapus(lokasi)} />
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <ServerPagination page={branches} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah lokasi' : 'Tambah lokasi'}</DialogTitle>
                        <DialogDescription>
                            Kode lokasi muncul pada setiap nomor dokumen, misalnya GRN/WH01/2026-08/0001.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Kode" required error={form.errors.branch_code}>
                                <Input
                                    value={form.data.branch_code}
                                    onChange={(e) => form.setData('branch_code', e.target.value.toUpperCase())}
                                    className="h-8 font-mono uppercase"
                                    maxLength={10}
                                    placeholder="BR01"
                                />
                            </Field>
                            <Field label="Nama lokasi" required error={form.errors.branch_name} className="col-span-2">
                                <Input
                                    value={form.data.branch_name}
                                    onChange={(e) => form.setData('branch_name', e.target.value)}
                                    className="h-8"
                                    placeholder="Sumber Makmur — Pusat"
                                />
                            </Field>
                        </div>

                        <Field
                            label="Tipe lokasi"
                            required
                            error={form.errors.branch_type}
                            hint={types.find((t) => t.value === form.data.branch_type)?.hint}
                        >
                            <NativeSelect
                                value={form.data.branch_type}
                                onChange={gantiTipe}
                                options={types.map((t) => ({ value: t.value, label: t.label }))}
                            />
                        </Field>

                        <Field label="Alamat" error={form.errors.address}>
                            <Input
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                                className="h-8"
                            />
                        </Field>

                        <div className="space-y-2 rounded-md border bg-muted/30 p-3">
                            <label className="flex items-start gap-2">
                                <Checkbox
                                    checked={form.data.can_sell}
                                    onCheckedChange={(v) => form.setData('can_sell', v === true)}
                                    className="mt-0.5"
                                />
                                <span className="text-xs">
                                    <span className="font-medium">Boleh melayani penjualan</span>
                                    <span className="block text-2xs text-muted-foreground">
                                        Kalau dimatikan, lokasi ini menolak transaksi kasir dan pembukaan shift. Stok,
                                        penerimaan, dan transfer tetap jalan.
                                    </span>
                                </span>
                            </label>
                            {form.errors.can_sell && (
                                <p className="text-2xs font-medium text-destructive">{form.errors.can_sell}</p>
                            )}

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
