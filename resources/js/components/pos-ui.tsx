import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';

/* =============================================================== format */

const rupiah = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

/** Rp1.234.500 — tanpa desimal, sesuai aturan penyimpanan IDR. */
export function formatRupiah(value: number, withPrefix = true): string {
    const sign = value < 0 ? '-' : '';
    const body = rupiah.format(Math.abs(Math.round(value)));
    return `${sign}${withPrefix ? 'Rp' : ''}${body}`;
}

/** 4,8 jt / 682 rb — untuk kartu KPI dan sumbu grafik. */
export function formatRupiahShort(value: number): string {
    const abs = Math.abs(value);
    const sign = value < 0 ? '-' : '';
    if (abs >= 1_000_000_000) return `${sign}Rp${(abs / 1_000_000_000).toFixed(1).replace('.', ',')} M`;
    if (abs >= 1_000_000) return `${sign}Rp${(abs / 1_000_000).toFixed(1).replace('.', ',')} jt`;
    if (abs >= 1_000) return `${sign}Rp${Math.round(abs / 1_000)} rb`;
    return `${sign}Rp${abs}`;
}

/** Qty: bilangan bulat tanpa desimal, item timbangan sampai 2 desimal. */
export function formatQty(value: number): string {
    return Number.isInteger(value)
        ? new Intl.NumberFormat('id-ID').format(value)
        : new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

/**
 * Pembulatan pembayaran tunai (Addendum §51.4).
 * Mengembalikan selisihnya (+/-), bukan nilai yang dibayar — nilai penjualan
 * dan pajak tidak boleh ikut berubah.
 */
export function hitungPembulatan(
    netTotal: number,
    unit: number,
    mode: 'NEAREST' | 'DOWN' | 'UP',
): number {
    if (unit <= 0) return 0;
    const dibayar =
        mode === 'DOWN'
            ? Math.floor(netTotal / unit) * unit
            : mode === 'UP'
              ? Math.ceil(netTotal / unit) * unit
              : Math.round(netTotal / unit) * unit;
    return dibayar - netTotal;
}

export function formatDate(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
    return `${d} ${bulan[m - 1]} ${y}`;
}

/* ============================================================ page header */

export function PageHeader({
    title,
    subtitle,
    action,
}: {
    title: string;
    subtitle?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
            </div>
            {action && <div className="flex items-center gap-2">{action}</div>}
        </div>
    );
}

/* ============================================================== stat card */

export function StatCard({
    label,
    value,
    delta,
    caption,
    icon,
    tone = 'default',
}: {
    label: string;
    value: string;
    delta?: number;
    caption?: string;
    icon?: ReactNode;
    tone?: 'default' | 'positive' | 'negative';
}) {
    const positive = (delta ?? 0) >= 0;

    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="label-eyebrow">{label}</span>
                {icon && <span className="text-muted-foreground/70">{icon}</span>}
            </div>
            <p
                className={cn(
                    'mt-2 font-mono text-3xl font-semibold tracking-tight tabular',
                    tone === 'positive' && 'text-success',
                    tone === 'negative' && 'text-destructive',
                )}
            >
                {value}
            </p>
            <div className="mt-1.5 flex items-center gap-1.5">
                {delta !== undefined && (
                    <span
                        className={cn(
                            'rounded px-1.5 py-0.5 font-mono text-2xs font-semibold',
                            positive ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive',
                        )}
                    >
                        {positive ? '+' : ''}
                        {delta.toFixed(1).replace('.', ',')}%
                    </span>
                )}
                {caption && <span className="text-xs text-muted-foreground">{caption}</span>}
            </div>
        </div>
    );
}

/* =============================================================== pencarian */

export function SearchInput({
    value,
    onChange,
    placeholder = 'Cari...',
    className,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    className?: string;
}) {
    return (
        <div className={cn('relative', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="h-8 pl-8 text-sm"
            />
        </div>
    );
}

/* ============================================================ status chip */

const STATUS_TONE: Record<string, string> = {
    POSTED: 'bg-success/10 text-success ring-success/20',
    DRAFT: 'bg-muted text-muted-foreground ring-border',
    VOID: 'bg-destructive/10 text-destructive ring-destructive/20',
    OPEN: 'bg-success/10 text-success ring-success/20',
    CLOSED: 'bg-muted text-muted-foreground ring-border',
    CLOSING: 'bg-warning/15 text-warning-foreground ring-warning/30 dark:text-warning',
    REOPENED: 'bg-warning/15 text-warning-foreground ring-warning/30 dark:text-warning',
};

const STATUS_LABEL: Record<string, string> = {
    POSTED: 'Diposting',
    DRAFT: 'Draft',
    VOID: 'Dibatalkan',
    OPEN: 'Terbuka',
    CLOSED: 'Ditutup',
    CLOSING: 'Menutup',
    REOPENED: 'Dibuka ulang',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-semibold ring-1 ring-inset',
                STATUS_TONE[status] ?? 'bg-muted text-muted-foreground ring-border',
            )}
        >
            {STATUS_LABEL[status] ?? status}
        </span>
    );
}

/* ============================================================== baris kosong */

export function EmptyRow({ colSpan, icon, message }: { colSpan: number; icon?: ReactNode; message: string }) {
    return (
        <TableRow className="hover:bg-transparent">
            <TableCell colSpan={colSpan} className="py-12 text-center">
                <div className="flex flex-col items-center gap-2 text-muted-foreground">
                    {icon && <span className="[&>svg]:h-6 [&>svg]:w-6 opacity-50">{icon}</span>}
                    <span className="text-sm">{message}</span>
                </div>
            </TableCell>
        </TableRow>
    );
}

/* ================================================================ paginasi */

export function usePagination<T>(items: T[], perPage = 10) {
    const [page, setPage] = useState(1);
    const totalPages = Math.max(1, Math.ceil(items.length / perPage));
    const current = Math.min(page, totalPages);

    const paged = useMemo(
        () => items.slice((current - 1) * perPage, current * perPage),
        [items, current, perPage],
    );

    return { paged, page: current, totalPages, setPage, total: items.length, perPage };
}

export function TablePagination({
    page,
    totalPages,
    setPage,
    total,
    perPage,
}: {
    page: number;
    totalPages: number;
    setPage: (p: number) => void;
    total: number;
    perPage: number;
}) {
    if (total === 0) return null;
    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);

    return (
        <div className="flex items-center justify-between border-t px-4 py-2.5">
            <p className="text-xs text-muted-foreground tabular">
                {from}–{to} dari {total} baris
            </p>
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                    aria-label="Halaman sebelumnya"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                </Button>
                <span className="px-1 font-mono text-xs tabular">
                    {page} / {totalPages}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    disabled={page >= totalPages}
                    onClick={() => setPage(page + 1)}
                    aria-label="Halaman berikutnya"
                >
                    <ChevronRight className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}

/* ================================================================= grafik */

/**
 * Grafik batang ganda omzet vs HPP. Digambar manual, tanpa library.
 * Tinggi batang dihitung dalam piksel — tinggi persen tidak dapat diandalkan
 * di dalam flex item yang tingginya berasal dari flex-basis.
 */
export function BarChart({
    data,
    height = 168,
}: {
    data: { date: string; omzet: number; hpp: number }[];
    height?: number;
}) {
    const max = Math.max(...data.map((d) => Math.max(d.omzet, d.hpp))) || 1;
    const labelHeight = 18;
    const plotHeight = Math.max(height - labelHeight, 24);

    return (
        <div className="flex items-end gap-1.5" style={{ height }}>
            {data.map((d) => (
                <div key={d.date} className="group relative flex flex-1 flex-col items-center">
                    <div
                        className="flex w-full items-end justify-center gap-[2px]"
                        style={{ height: plotHeight }}
                    >
                        <div
                            className="w-full max-w-2.5 rounded-t-[2px] bg-primary/85 transition-colors group-hover:bg-primary"
                            style={{ height: Math.max((d.omzet / max) * plotHeight, 2) }}
                        />
                        <div
                            className="w-full max-w-2.5 rounded-t-[2px] bg-primary/30 transition-colors group-hover:bg-primary/50"
                            style={{ height: Math.max((d.hpp / max) * plotHeight, 2) }}
                        />
                    </div>
                    <span className="mt-1 text-2xs text-muted-foreground">{d.date.split(' ')[0]}</span>

                    <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-md border bg-popover px-2 py-1.5 whitespace-nowrap shadow-md group-hover:block">
                        <p className="text-2xs font-semibold">{d.date}</p>
                        <p className="font-mono text-2xs text-muted-foreground">Omzet {formatRupiahShort(d.omzet)}</p>
                        <p className="font-mono text-2xs text-muted-foreground">HPP {formatRupiahShort(d.hpp)}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

/** Bar horizontal untuk komposisi (metode bayar, biaya per kategori). */
export function CompositionBar({
    items,
}: {
    items: { label: string; amount: number }[];
}) {
    const total = items.reduce((s, i) => s + i.amount, 0);
    const tones = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4', 'bg-chart-5'];

    return (
        <div className="space-y-3">
            <div className="flex h-2 overflow-hidden rounded-full bg-muted">
                {items.map((item, i) => (
                    <div
                        key={item.label}
                        className={tones[i % tones.length]}
                        style={{ width: `${(item.amount / total) * 100}%` }}
                    />
                ))}
            </div>
            <ul className="space-y-1.5">
                {items.map((item, i) => (
                    <li key={item.label} className="flex items-center gap-2">
                        <span className={cn('h-2 w-2 shrink-0 rounded-sm', tones[i % tones.length])} />
                        <span className="flex-1 text-sm">{item.label}</span>
                        <span className="font-mono text-sm tabular">{formatRupiah(item.amount)}</span>
                        <span className="w-10 text-right font-mono text-2xs text-muted-foreground tabular">
                            {Math.round((item.amount / total) * 100)}%
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ================================================================== kartu */

export function Panel({
    title,
    description,
    action,
    children,
    className,
    bodyClassName,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}) {
    return (
        <section className={cn('rounded-lg border bg-card', className)}>
            <header className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <div>
                    <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
                    {description && <p className="text-xs text-muted-foreground">{description}</p>}
                </div>
                {action}
            </header>
            <div className={cn('p-4', bodyClassName)}>{children}</div>
        </section>
    );
}
