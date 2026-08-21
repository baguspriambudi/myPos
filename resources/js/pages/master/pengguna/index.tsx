import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
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
    { title: 'Pengguna', href: '/master/pengguna' },
];

interface Pengguna {
    id: number;
    username: string;
    fullName: string;
    role: string;
    roleLabel: string;
    branchId: number | null;
    branchName: string | null;
    branchAccessIds: number[];
    active: boolean;
    isSelf: boolean;
}

interface Lokasi {
    id: number;
    name: string;
    code: string;
    typeLabel: string;
    canSell: boolean;
}

const ROLE_ADMIN = ['OWNER', 'SUPER_ADMIN'];

export default function PenggunaIndex() {
    const { users, filters, branches, roles, auth } = usePage().props as unknown as {
        users: Paginated<Pengguna>;
        filters: { q: string };
        branches: Lokasi[];
        roles: { value: string; label: string; hint: string }[];
        auth: { user: { isMasterAdmin: boolean } };
    };

    const [sedangDiubah, setSedangDiubah] = useState<Pengguna | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const bolehUbah = auth.user.isMasterAdmin;

    const form = useForm({
        username: '',
        full_name: '',
        role: 'KASIR',
        branch_id: '' as string | number,
        branch_access: [] as number[],
        password: '',
        active: true as boolean,
    });

    const peranAdmin = useMemo(() => ROLE_ADMIN.includes(form.data.role), [form.data.role]);

    const bukaTambah = () => {
        setSedangDiubah(null);
        form.setData({ username: '', full_name: '', role: 'KASIR', branch_id: '', branch_access: [], password: '', active: true });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const bukaUbah = (u: Pengguna) => {
        setSedangDiubah(u);
        form.setData({
            username: u.username,
            full_name: u.fullName,
            role: u.role,
            branch_id: u.branchId ?? '',
            branch_access: u.branchAccessIds,
            password: '',
            active: u.active,
        });
        form.clearErrors();
        setDialogTerbuka(true);
    };

    const toggleAkses = (id: number) => {
        form.setData(
            'branch_access',
            form.data.branch_access.includes(id)
                ? form.data.branch_access.filter((x) => x !== id)
                : [...form.data.branch_access, id],
        );
    };

    const simpan = () => {
        const opsi = { preserveScroll: true, onSuccess: () => setDialogTerbuka(false) };

        if (sedangDiubah) {
            form.put(`/master/pengguna/${sedangDiubah.id}`, opsi);
        } else {
            form.post('/master/pengguna', opsi);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Master Pengguna" />

            <div className="space-y-4 p-4">
                <PageHeader
                    title="Pengguna"
                    subtitle="Akun dibuat di sini. Tidak ada pendaftaran mandiri dan tidak ada reset lewat email."
                    action={
                        bolehUbah && (
                            <Button size="sm" className="h-8" onClick={bukaTambah}>
                                <Plus className="h-3.5 w-3.5" /> Tambah pengguna
                            </Button>
                        )
                    }
                />

                <FlashMessage />

                <Panel
                    title="Daftar pengguna"
                    description={`${users.total} pengguna terdaftar`}
                    bodyClassName="p-0"
                    action={<ServerSearch routeUrl="/master/pengguna" initial={filters.q} placeholder="Cari nama atau username..." className="w-56" />}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead className="w-32">Username</TableHead>
                                <TableHead className="w-32">Peran</TableHead>
                                <TableHead>Akses lokasi</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                                {bolehUbah && <TableHead className="w-32 text-right">Aksi</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 && (
                                <EmptyRow colSpan={bolehUbah ? 6 : 5} icon={<Users />} message="Belum ada pengguna." />
                            )}

                            {users.data.map((u) => (
                                <TableRow key={u.id}>
                                    <TableCell className="font-medium">
                                        {u.fullName}
                                        {u.isSelf && <span className="ml-1.5 text-2xs text-muted-foreground">(Anda)</span>}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">{u.username}</TableCell>
                                    <TableCell>
                                        <Chip tone={ROLE_ADMIN.includes(u.role) ? 'accent' : 'neutral'}>{u.roleLabel}</Chip>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {ROLE_ADMIN.includes(u.role)
                                            ? 'Semua lokasi'
                                            : branches
                                                  .filter((b) => u.branchAccessIds.includes(b.id) || b.id === u.branchId)
                                                  .map((b) => b.code)
                                                  .join(', ') || '—'}
                                    </TableCell>
                                    <TableCell>
                                        <ActiveBadge active={u.active} />
                                    </TableCell>
                                    {bolehUbah && (
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditButton onClick={() => bukaUbah(u)} />
                                                {!u.isSelf && (
                                                    <DeleteButton onConfirm={() => form.delete(`/master/pengguna/${u.id}`, { preserveScroll: true })} />
                                                )}
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ServerPagination page={users} />
                </Panel>
            </div>

            <Dialog open={dialogTerbuka} onOpenChange={setDialogTerbuka}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{sedangDiubah ? 'Ubah pengguna' : 'Tambah pengguna'}</DialogTitle>
                        <DialogDescription>Username dipakai untuk masuk dan harus unik dalam satu usaha.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Nama lengkap" required error={form.errors.full_name}>
                                <Input value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} className="h-8" />
                            </Field>
                            <Field label="Username" required error={form.errors.username}>
                                <Input
                                    value={form.data.username}
                                    onChange={(e) => form.setData('username', e.target.value.toLowerCase())}
                                    className="h-8 font-mono lowercase"
                                />
                            </Field>
                        </div>

                        <Field
                            label="Peran"
                            required
                            error={form.errors.role}
                            hint={roles.find((r) => r.value === form.data.role)?.hint}
                        >
                            <NativeSelect
                                value={form.data.role}
                                onChange={(v) => form.setData('role', v)}
                                options={roles.map((r) => ({ value: r.value, label: r.label }))}
                            />
                        </Field>

                        <Field
                            label="Kata sandi"
                            required={!sedangDiubah}
                            error={form.errors.password}
                            hint={sedangDiubah ? 'Kosongkan bila tidak ingin mengganti' : 'Minimal 6 karakter'}
                        >
                            <Input
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                className="h-8"
                                autoComplete="new-password"
                            />
                        </Field>

                        {peranAdmin ? (
                            <div className="rounded-md border border-dashed bg-muted/30 px-3 py-2 text-2xs text-muted-foreground">
                                Pemilik dan Super Admin otomatis melihat seluruh lokasi, jadi daftar akses tidak berlaku.
                            </div>
                        ) : (
                            <>
                                <Field label="Lokasi utama" error={form.errors.branch_id} hint="Lokasi yang terbuka saat pertama masuk">
                                    <NativeSelect
                                        value={form.data.branch_id}
                                        onChange={(v) => form.setData('branch_id', v)}
                                        placeholder="Pilih lokasi"
                                        options={branches.map((b) => ({ value: b.id, label: `${b.code} — ${b.name}` }))}
                                    />
                                </Field>

                                <div className="space-y-1.5">
                                    <p className="text-xs font-medium">Akses lokasi lain</p>
                                    {form.errors.branch_access && (
                                        <p className="text-2xs font-medium text-destructive">{form.errors.branch_access}</p>
                                    )}
                                    <div className="grid gap-1.5 rounded-md border p-2.5">
                                        {branches.map((b) => (
                                            <label key={b.id} className="flex items-center gap-2">
                                                <Checkbox
                                                    checked={form.data.branch_access.includes(b.id)}
                                                    onCheckedChange={() => toggleAkses(b.id)}
                                                />
                                                <span className="flex-1 text-xs">
                                                    <span className="font-mono font-semibold">{b.code}</span> {b.name}
                                                </span>
                                                <Chip tone={b.canSell ? 'neutral' : 'warning'}>{b.typeLabel}</Chip>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}

                        <label className="flex items-center gap-2">
                            <Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} />
                            <span className="text-xs font-medium">Aktif</span>
                        </label>
                        {form.errors.active && <p className="text-2xs font-medium text-destructive">{form.errors.active}</p>}
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
