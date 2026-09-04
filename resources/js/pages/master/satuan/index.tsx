import { ActiveBadge, DeleteButton, EditButton, Field, FlashMessage, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Lock, Plus, Ruler } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Satuan', href: '/master/satuan' },
];

interface Satuan {
    id: number;
    name: string;
    description: string | null;
    active: boolean;
    /** Berapa baris satuan produk yang memakai nama ini. */
    assignedCount: number;
}

export default function SatuanIndex() {
    const { units, filters, auth } = usePage().props as unknown as {
        units: Paginated<Satuan>;
        filters: { q: string };
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Satuan | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;
    const namaTerkunci = (sedangDiubah?.assignedCount ?? 0) > 0;

    const form = useForm({ unit_name: '', description: '', active: true as boolean });

    const buka = (s: Satuan | null) => {
        setSedangDiubah(s);
        form.setData({ unit_name: s?.name ?? '', description: s?.description ?? '', active: s?.active ?? true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/satuan/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/satuan', opsi);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Satuan" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Satuan"
                    subtitle="Daftar nama satuan yang boleh dipakai. Saat membuat produk, satuan dipilih dari sini — tidak diketik."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah satuan
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar satuan"
                    description={`${units.total} satuan terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/satuan" initial={filters.q} placeholder="Cari satuan..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-32">Nama</TableHead>
                                <TableHead>Keterangan</TableHead>
                                <TableHead className="w-28 text-right">Dipakai</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {units.data.length === 0 && <EmptyRow colSpan={bolehUbah ? 5 : 4} icon={<Ruler />} message="Belum ada satuan." />}

                            {units.data.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell>
                                        <span className="inline-flex items-center gap-1 font-mono font-semibold">
                                            {s.name}
                                            {s.assignedCount > 0 && (
                                                <Lock
                                                    className="text-muted-foreground h-3 w-3"
                                                    aria-label="Nama terkunci karena sudah dipakai produk"
                                                />
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-xs">{s.description ?? '—'}</TableCell>
                                    <TableCell className="tabular text-right font-mono text-xs">
                                        {s.assignedCount > 0 ? `${s.assignedCount} produk` : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={s.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(s)} />
                                                <DeleteButton
                                                    onConfirm={() => form.delete(`/master/satuan/${s.id}`, { preserveScroll: true })}
                                                    disabled={s.assignedCount > 0}
                                                    disabledReason="Sudah dipakai produk — nonaktifkan saja."
                                                />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={units} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah satuan' : 'Tambah satuan'}</DialogTitle>
                        <DialogDescription>Satuan hanya menyimpan namanya. Berapa isi satu DUS ditentukan di masing-masing produk.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field
                            label="Nama satuan"
                            required
                            error={form.errors.unit_name}
                            hint={
                                namaTerkunci
                                    ? `Sudah dipakai ${sedangDiubah?.assignedCount} baris satuan produk — namanya tidak dapat diubah.`
                                    : 'Ditulis huruf besar, mis. PCS, DUS, KG.'
                            }
                        >
                            <Input
                                value={form.data.unit_name}
                                onChange={(e) => form.setData('unit_name', e.target.value.toUpperCase())}
                                className="h-8 font-mono uppercase"
                                placeholder="DUS"
                                disabled={namaTerkunci}
                            />
                        </Field>
                        <Field label="Keterangan" error={form.errors.description}>
                            <Input
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                className="h-8"
                                placeholder="Kemasan kardus dari pemasok"
                            />
                        </Field>
                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} />
                            <span className="text-xs font-medium">Aktif</span>
                        </label>
                        <p className="text-2xs text-muted-foreground">
                            Satuan nonaktif tidak lagi ditawarkan saat membuat produk, tetapi produk yang sudah memakainya tidak terganggu.
                        </p>
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
