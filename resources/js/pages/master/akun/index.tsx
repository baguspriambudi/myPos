import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BookOpen, Plus } from 'lucide-react';
import { useState } from 'react';
import { Chip, DeleteButton, EditButton, Field, FlashMessage, NativeSelect, ServerPagination, ServerSearch, type Paginated } from '@/components/master-ui';
import { EmptyRow, PageHeader, Panel } from '@/components/pos-ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master', href: '#' },
    { title: 'Akun', href: '/master/akun' },
];

interface Akun {
    id: number;
    code: string;
    name: string;
    type: string;
    typeLabel: string;
    isGrni: boolean;
}

export default function AkunIndex() {
    const { accounts, filters, types, auth } = usePage().props as unknown as {
        accounts: Paginated<Akun>;
        filters: { q: string; type: string | null };
        types: { value: string; label: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Akun | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({ account_code: '', account_name: '', account_type: 'ASSET' });

    const buka = (a: Akun | null) => {
        setSedangDiubah(a);
        form.setData({ account_code: a?.code ?? '', account_name: a?.name ?? '', account_type: a?.type ?? 'ASSET' });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };
        sedangDiubah ? form.put(`/master/akun/${sedangDiubah.id}`, opsi) : form.post('/master/akun', opsi);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Daftar Akun" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Daftar Akun"
                    subtitle="Bagan akun untuk jurnal closing dan pemetaan kategori biaya."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={() => buka(null)}>
                                <Plus className="h-3.5 w-3.5" /> Tambah akun
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Bagan akun"
                    description={`${accounts.total} akun terdaftar`}
                    bodyClassName="p-0"
                    action={
                        <div className="flex items-center gap-2">
                            <NativeSelect
                                value={filters.type ?? ''}
                                onChange={(v) => router.get('/master/akun', v === '' ? {} : { type: v }, { preserveState: true, preserveScroll: true })}
                                placeholder="Semua tipe"
                                options={types.map((t) => ({ value: t.value, label: t.label }))}
                                className="w-36"
                            />
                            <ServerSearch routeUrl="/master/akun" initial={filters.q} placeholder="Cari kode atau nama..." className="w-52" />
                        </div>
                    }
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-28">Kode</TableHead>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-32">Tipe</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 4 : 3} icon={<BookOpen />} message="Belum ada akun." />
                            )}

                            {accounts.data.map((a) => (
                                <TableRow key={a.id}>
                                    <TableCell className="font-mono text-xs font-semibold tabular">{a.code}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-medium">{a.name}</span>
                                            {a.isGrni && <Chip tone="warning">Dipakai penerimaan barang</Chip>}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Chip>{a.typeLabel}</Chip>
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => buka(a)} />
                                                {!a.isGrni && (
                                                    <DeleteButton onConfirm={() => form.delete(`/master/akun/${a.id}`, { preserveScroll: true })} />
                                                )}
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={accounts} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah akun' : 'Tambah akun'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Kode" required error={form.errors.account_code}>
                                <Input
                                    value={form.data.account_code}
                                    onChange={(e) => form.setData('account_code', e.target.value)}
                                    className="h-8 font-mono"
                                    placeholder="1-1100"
                                />
                            </Field>
                            <Field label="Nama akun" required error={form.errors.account_name} className="col-span-2">
                                <Input value={form.data.account_name} onChange={(e) => form.setData('account_name', e.target.value)} className="h-8" />
                            </Field>
                        </div>
                        <Field label="Tipe akun" required error={form.errors.account_type}>
                            <NativeSelect
                                value={form.data.account_type}
                                onChange={(v) => form.setData('account_type', v)}
                                options={types.map((t) => ({ value: t.value, label: t.label }))}
                            />
                        </Field>
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
