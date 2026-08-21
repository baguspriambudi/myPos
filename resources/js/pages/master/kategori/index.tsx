import { Head, useForm, usePage } from '@inertiajs/react';
import { FolderTree, Plus } from 'lucide-react';
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
    { title: 'Kategori Produk', href: '/master/kategori' },
];

interface Kategori {
    id: number;
    name: string;
    active: boolean;
    productsCount: number;
}

export default function KategoriIndex() {
    const { categories, filters, auth } = usePage().props as unknown as {
        categories: Paginated<Kategori>;
        filters: { q: string };
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Kategori | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({ category_name: '', active: true as boolean });

    const buka = (k: Kategori | null) => {
        setSedangDiubah(k);
        form.setData({ category_name: k?.name ?? '', active: k?.active ?? true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/kategori/${sedangDiubah.id}`, opsi) : form.post('/master/kategori', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kategori Produk" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Kategori Produk"
                    subtitle="Dipakai untuk mengelompokkan produk di laporan dan sasaran diskon."
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
                    title="Daftar kategori"
                    description={`${categories.total} kategori terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/kategori" initial={filters.q} placeholder="Cari kategori..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-28 text-right">Produk</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 4 : 3} icon={<FolderTree />} message="Belum ada kategori." />
                            )}

                            {categories.data.map((k) => (
                                <TableRow key={k.id}>
                                    <TableCell className="font-medium">{k.name}</TableCell>
                                    <TableCell className="text-right font-mono text-xs tabular">{k.productsCount}</TableCell>
                                    <TableCell>
                                        <ActiveBadge active={k.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(k)} />
                                                <DeleteButton onConfirm={() => form.delete(`/master/kategori/${k.id}`, { preserveScroll: true })} />
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
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah kategori' : 'Tambah kategori'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Field label="Nama kategori" required error={form.errors.category_name}>
                            <Input
                                value={form.data.category_name}
                                onChange={(e) => form.setData('category_name', e.target.value)}
                                className="h-8"
                                placeholder="Sembako"
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
