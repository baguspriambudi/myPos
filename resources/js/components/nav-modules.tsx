import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

export interface MenuLink {
    title: string;
    url: string;
    icon?: LucideIcon;
}

/** Pengelompokan di dalam satu modul: Transaksi, Master, atau Laporan. */
export interface MenuSection {
    label: string;
    items: MenuLink[];
}

export interface MenuModule {
    title: string;
    icon: LucideIcon;
    sections: MenuSection[];
}

export type MenuEntry = MenuLink | MenuModule;

function isModule(entry: MenuEntry): entry is MenuModule {
    return 'sections' in entry;
}

/**
 * Modul mana saja yang sedang terbuka.
 *
 * AppShell remount tiap navigasi Inertia, jadi state React biasa akan menutup
 * kembali seluruh modul setiap kali pindah halaman. Disimpan di level modul —
 * cara yang sama dipakai posisi scroll sidebar.
 */
const modulTerbuka: Record<string, boolean> = {};

export function NavModules({ entries }: { entries: MenuEntry[] }) {
    const page = usePage();
    const { state, setOpen } = useSidebar();
    // Dipakai hanya untuk memaksa render ulang saat modul dibuka/ditutup;
    // nilainya sendiri tidak berarti apa-apa.
    const [, paksaRender] = useState(0);

    // Cocok per-segmen path: /master/kategori tidak boleh ikut aktif saat
    // halaman /master/kategori-biaya, tapi /master/harga/:id tetap aktif untuk
    // menu /master/harga.
    const currentPath = page.url.split('?')[0];
    const cocok = (url: string) => currentPath === url || currentPath.startsWith(`${url}/`);

    // Bila dua menu sama-sama cocok — /stok dan /stok/penyesuaian — hanya yang
    // paling khusus yang ditandai aktif. Tanpa ini, menu induk ikut menyala dan
    // pengguna tidak tahu sedang berada di layar yang mana.
    const semuaUrl = entries.flatMap((e) => (isModule(e) ? e.sections.flatMap((s) => s.items.map((i) => i.url)) : [e.url]));
    const terbaik = semuaUrl.filter(cocok).sort((a, b) => b.length - a.length)[0] ?? null;
    const aktif = (url: string) => url === terbaik;
    const modulAktif = (m: MenuModule) => m.sections.some((s) => s.items.some((i) => aktif(i.url)));

    function toggle(m: MenuModule, terbuka: boolean) {
        // Sidebar yang menyusut jadi ikon tidak punya tempat untuk anak menu;
        // mengkliknya berarti "buka sidebarnya", bukan "buka diam-diam".
        if (state === 'collapsed') {
            setOpen(true);
            modulTerbuka[m.title] = true;
        } else {
            modulTerbuka[m.title] = terbuka;
        }

        paksaRender((n) => n + 1);
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                {entries.map((entry) => {
                    if (!isModule(entry)) {
                        return (
                            <SidebarMenuItem key={entry.url}>
                                <SidebarMenuButton asChild isActive={aktif(entry.url)} tooltip={entry.title}>
                                    <Link href={entry.url} prefetch>
                                        {entry.icon && <entry.icon />}
                                        <span>{entry.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    }

                    const berisiHalamanAktif = modulAktif(entry);
                    // Modul yang memuat halaman berjalan terbuka sendiri, kecuali
                    // pengguna memang menutupnya.
                    const terbuka = modulTerbuka[entry.title] ?? berisiHalamanAktif;

                    return (
                        <Collapsible key={entry.title} asChild open={terbuka} onOpenChange={(o) => toggle(entry, o)}>
                            <SidebarMenuItem>
                                <CollapsibleTrigger asChild>
                                    <SidebarMenuButton
                                        tooltip={entry.title}
                                        // Saat tertutup, modul memakai tanda aktif agar halaman
                                        // yang sedang dibuka tetap ketahuan ada di dalamnya.
                                        isActive={berisiHalamanAktif && !terbuka}
                                    >
                                        <entry.icon />
                                        <span>{entry.title}</span>
                                        <ChevronRight
                                            className={cn('ml-auto h-4 w-4 shrink-0 transition-transform duration-200', terbuka && 'rotate-90')}
                                        />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>

                                <CollapsibleContent>
                                    {entry.sections.map((section) => (
                                        <div key={section.label} className="mt-1 first:mt-0.5">
                                            <p className="label-eyebrow px-3.5 pb-0.5 pl-6">{section.label}</p>
                                            <SidebarMenuSub>
                                                {section.items.map((item) => (
                                                    <SidebarMenuSubItem key={item.url}>
                                                        <SidebarMenuSubButton asChild isActive={aktif(item.url)}>
                                                            <Link href={item.url} prefetch>
                                                                {item.icon && <item.icon />}
                                                                <span>{item.title}</span>
                                                            </Link>
                                                        </SidebarMenuSubButton>
                                                    </SidebarMenuSubItem>
                                                ))}
                                            </SidebarMenuSub>
                                        </div>
                                    ))}
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
