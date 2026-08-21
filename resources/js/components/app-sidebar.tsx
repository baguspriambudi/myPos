import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { useLayoutEffect } from 'react';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import {
    BadgePercent,
    BookOpen,
    Boxes,
    Building2,
    CalendarRange,
    FileBarChart,
    FolderTree,
    LayoutGrid,
    Package,
    Percent,
    Receipt,
    ReceiptText,
    ScanLine,
    ShoppingCart,
    Store,
    Tags,
    Truck,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from './app-logo';

const transaksiNav: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Kasir', url: '/kasir', icon: ScanLine },
    { title: 'Penjualan', url: '/penjualan', icon: ShoppingCart },
    { title: 'Pembelian', url: '/pembelian', icon: ReceiptText },
];

const persediaanNav: NavItem[] = [
    { title: 'Produk', url: '/produk', icon: Package },
    { title: 'Kartu Stok', url: '/stok', icon: Boxes },
];

const keuanganNav: NavItem[] = [
    { title: 'Kas & Biaya', url: '/biaya', icon: Wallet },
    { title: 'Periode', url: '/periode', icon: CalendarRange },
    { title: 'Laporan', url: '/laporan', icon: FileBarChart },
];

// Master sudah memakai database; menu di atas masih memakai data contoh.
const masterNav: NavItem[] = [
    { title: 'Profil Usaha', url: '/master/usaha', icon: Building2 },
    { title: 'Lokasi', url: '/master/lokasi', icon: Store },
    { title: 'Pengguna', url: '/master/pengguna', icon: Users },
    { title: 'Kategori Produk', url: '/master/kategori', icon: FolderTree },
    { title: 'Produk & Satuan', url: '/master/produk', icon: Package },
    { title: 'Daftar Harga', url: '/master/harga', icon: Tags },
    { title: 'Pelanggan', url: '/master/pelanggan', icon: UserRound },
    { title: 'Pemasok', url: '/master/pemasok', icon: Truck },
    { title: 'Pajak', url: '/master/pajak', icon: Percent },
    { title: 'Diskon', url: '/master/diskon', icon: BadgePercent },
    { title: 'Kategori Biaya', url: '/master/kategori-biaya', icon: Receipt },
    { title: 'Daftar Akun', url: '/master/akun', icon: BookOpen },
];

// AppShell ikut remount tiap navigasi Inertia (layout tidak dipersistenkan),
// sehingga scroll menu sidebar reset ke atas. Scroll disimpan di level modul
// supaya tetap hidup antar-navigasi dan dipulihkan saat komponen dipasang lagi.
let sidebarScrollTop = 0;

export function AppSidebar() {
    useLayoutEffect(() => {
        const sc = document.querySelector('[data-sidebar="content"]');
        if (!sc) return;

        if (sidebarScrollTop > 0) {
            sc.scrollTop = sidebarScrollTop;
        }

        const onScroll = () => {
            sidebarScrollTop = sc.scrollTop;
        };

        sc.addEventListener('scroll', onScroll, { passive: true });

        return () => sc.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={transaksiNav} label="Transaksi" />
                <NavMain items={persediaanNav} label="Persediaan" />
                <NavMain items={keuanganNav} label="Keuangan" />
                <NavMain items={masterNav} label="Master" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
