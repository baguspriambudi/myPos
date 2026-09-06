import { usePage } from '@inertiajs/react';
import { Store } from 'lucide-react';

export default function AppLogo() {
    // Kode lokasi dan nama usaha diambil dari props bersama, bukan ditulis
    // tetap: pengguna yang berpindah lokasi harus melihat perpindahannya di
    // kepala sidebar juga, bukan hanya di isi halaman.
    const { auth } = usePage().props as unknown as {
        auth: { owner: { name: string } | null; branch: { code: string; name: string } | null };
    };

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <Store className="size-4" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate text-sm leading-tight font-semibold">myPOS</span>
                <span className="text-2xs text-muted-foreground truncate leading-tight">
                    {auth.branch ? `${auth.branch.code} · ` : ''}
                    {auth.owner?.name ?? 'Belum ada usaha'}
                </span>
            </div>
        </>
    );
}
