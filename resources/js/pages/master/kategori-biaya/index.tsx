import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Receipt } from 'lucide-react';
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
    { title: 'Kategori Biaya', href: '/master/kategori-biaya' },
];

interface KategoriBiaya {
    id: number;
    name: string;
    accountId: number | null;
    accountLabel: string | null;
    active: boolean;
}

export default function KategoriBiayaIndex() {
    const { categories, filters, accounts, auth } = usePage().props as unknown as {
        categories: Paginated<KategoriBiaya>;
        filters: { q: string };
        accounts: { id: number; label: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<KategoriBiaya | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({ category_name: '', account_id: '' as string | number, active: true as boolean });

    const buka = (k: KategoriBiaya | null) => {
        setSedangDiubah(k);
        form.setData({ category_name: k?.name ?? '', account_id: k?.accountId ?? '', active: k?.active ?? true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/kategori-biaya/${sedangDiubah.id}`, opsi) : form.post('/master/kategori-biaya', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kategori Biaya" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Kategori Biaya"
                    subtitle="Biaya operasional tidak menambah nilai persediaan; ia memotong laba kotor menjadi laba bersih."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah kategori
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar kategori biaya"
                    description={`${categories.total} kategori terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/kategori-biaya" initial={filters.q} placeholder="Cari kategori..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Akun</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 4 : 3} icon={<Receipt />} message="Belum ada kategori biaya." />
                            )}

                            {categories.data.map((k) => (
                                <TableRow key={k.id}>
                                    <TableCell className="font-medium">{k.name}</TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">{k.accountLabel ?? '—'}</TableCell>
                                    <TableCell>
                                        <ActiveBadge active={k.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(k)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/kategori-biaya/${k.id}`, { preserveScroll: true })} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={categories} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah kategori biaya' : 'Tambah kategori biaya'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama kategori" required error={form.errors.category_name}>
                            <Input value={form.data.category_name} onChange={(e) => form.setData('category_name', e.target.value)} className="h-8" placeholder="Listrik & Air" />
                        </Field>
                        <Field label="Akun beban" error={form.errors.account_id} hint="Dipakai saat menyusun jurnal">
                            <NativeSelect
                                value={form.data.account_id}
                                onChange={(v) => form.setData('account_id', v)}
                                placeholder="Belum dipetakan"
                                options={accounts.map((a) => ({ value: a.id, label: a.label }))}
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
