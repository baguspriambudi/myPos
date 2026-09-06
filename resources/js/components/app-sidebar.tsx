import { NavModules, type MenuEntry } from '@/components/nav-modules';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/react';
import {
    ArrowRightLeft,
    BadgePercent,
    BookOpen,
    Boxes,
    Building2,
    CalendarRange,
    ClipboardList,
    FileBarChart,
    BookText,
    LineChart,
    PiggyBank,
    Warehouse,
    FolderTree,
    Coins,
    HandCoins,
    Landmark,
    LayoutGrid,
    Package,
    PackagePlus,
    Percent,
    Receipt,
    ReceiptText,
    Ruler,
    Scale,
    ScanLine,
    Settings,
    ShoppingBag,
    ShoppingCart,
    Store,
    Tags,
    Truck,
    Undo2,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import { useLayoutEffect } from 'react';
import AppLogo from './app-logo';

/**
 * Menu disusun per MODUL, dan di dalam tiap modul dipisah menurut perannya:
 * Transaksi (yang dikerjakan sehari-hari), Master (data acuannya), lalu
 * Laporan. Susunan ini menjawab pertanyaan "ini urusan apa" lebih dulu,
 * sebelum "layar mana" — daftar 13 master yang berderet rata tidak pernah
 * menjawab pertanyaan pertama.
 *
 * Bagian Laporan tiap modul terisi sejak 6 September 2026; menu tunggal
 * "/laporan" dibuang karena isinya sudah pindah ke modulnya masing-masing.
 */
const menu: MenuEntry[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },

    {
        title: 'Penjualan',
        icon: ShoppingCart,
        sections: [
            {
                label: 'Transaksi',
                items: [
                    { title: 'Kasir', url: '/kasir', icon: ScanLine },
                    { title: 'Nota Penjualan', url: '/penjualan', icon: ReceiptText },
                    { title: 'Retur Penjualan', url: '/retur/penjualan', icon: Undo2 },
                ],
            },
            {
                label: 'Master',
                items: [
                    { title: 'Pelanggan', url: '/master/pelanggan', icon: UserRound },
                    { title: 'Daftar Harga', url: '/master/harga', icon: Tags },
                    { title: 'Diskon', url: '/master/diskon', icon: BadgePercent },
                ],
            },
            {
                label: 'Laporan',
                items: [{ title: 'Laporan Penjualan', url: '/laporan/penjualan', icon: LineChart }],
            },
        ],
    },

    {
        title: 'Pembelian',
        icon: ShoppingBag,
        sections: [
            {
                label: 'Transaksi',
                items: [
                    { title: 'Penerimaan Barang', url: '/penerimaan', icon: PackagePlus },
                    { title: 'Faktur Pemasok', url: '/pembelian', icon: ReceiptText },
                    { title: 'Retur Pembelian', url: '/retur/pembelian', icon: Undo2 },
                ],
            },
            {
                label: 'Master',
                items: [{ title: 'Pemasok', url: '/master/pemasok', icon: Truck }],
            },
            {
                label: 'Laporan',
                items: [{ title: 'Laporan Pembelian', url: '/laporan/pembelian', icon: FileBarChart }],
            },
        ],
    },

    {
        title: 'Persediaan',
        icon: Boxes,
        sections: [
            {
                label: 'Transaksi',
                items: [
                    { title: 'Kartu Stok', url: '/stok', icon: Boxes },
                    { title: 'Penyesuaian Stok', url: '/stok/penyesuaian', icon: Scale },
                    { title: 'Transfer Antar-Lokasi', url: '/stok/transfer', icon: ArrowRightLeft },
                    { title: 'Stock Opname', url: '/stok/opname', icon: ClipboardList },
                ],
            },
            {
                label: 'Master',
                items: [
                    { title: 'Produk', url: '/master/produk', icon: Package },
                    { title: 'Kategori Produk', url: '/master/kategori', icon: FolderTree },
                    { title: 'Satuan', url: '/master/satuan', icon: Ruler },
                ],
            },
            {
                label: 'Laporan',
                items: [{ title: 'Laporan Persediaan', url: '/laporan/persediaan', icon: Warehouse }],
            },
        ],
    },

    {
        title: 'Banking',
        icon: Landmark,
        sections: [
            {
                label: 'Transaksi',
                items: [
                    { title: 'Kas & Biaya', url: '/biaya', icon: Wallet },
                    { title: 'Utang Usaha', url: '/utang', icon: HandCoins },
                    { title: 'Piutang Usaha', url: '/piutang', icon: Coins },
                    { title: 'Periode Pembukuan', url: '/periode', icon: CalendarRange },
                ],
            },
            {
                label: 'Master',
                items: [
                    { title: 'Kategori Biaya', url: '/master/kategori-biaya', icon: Receipt },
                    { title: 'Daftar Akun', url: '/master/akun', icon: BookOpen },
                    { title: 'Pajak', url: '/master/pajak', icon: Percent },
                ],
            },
            {
                label: 'Laporan',
                items: [
                    { title: 'Laba Rugi', url: '/laporan/laba-rugi', icon: PiggyBank },
                    { title: 'Buku Kas', url: '/laporan/kas', icon: BookText },
                ],
            },
        ],
    },

    {
        title: 'Pengaturan',
        icon: Settings,
        sections: [
            {
                label: 'Usaha',
                items: [
                    { title: 'Profil Usaha', url: '/master/usaha', icon: Building2 },
                    { title: 'Lokasi', url: '/master/lokasi', icon: Store },
                    { title: 'Pengguna', url: '/master/pengguna', icon: Users },
                ],
            },
        ],
    },
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
                <NavModules entries={menu} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
