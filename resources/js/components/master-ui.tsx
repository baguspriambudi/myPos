import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Pencil, Search, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/**
 * Potongan yang dipakai berulang di seluruh layar master.
 *
 * Sengaja terpisah dari pos-ui.tsx: yang di sana melayani layar operasional
 * dengan data contoh, yang di sini melayani data server (paginasi Laravel,
 * pencarian lewat query string, pesan flash).
 */

/* ============================================================ tipe paginator */

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

/* ================================================================ pencarian */

/**
 * Pencarian yang mengirim ke server, ditunda 300 ms agar tidak satu request
 * per ketukan. preserveState menjaga fokus kursor tetap di kotak pencarian.
 */
export function ServerSearch({
    routeUrl,
    initial = '',
    placeholder = 'Cari...',
    className,
}: {
    routeUrl: string;
    initial?: string;
    placeholder?: string;
    className?: string;
}) {
    const [value, setValue] = useState(initial);
    const pertamaKali = useRef(true);

    useEffect(() => {
        if (pertamaKali.current) {
            pertamaKali.current = false;
            return;
        }

        const timer = setTimeout(() => {
            router.get(routeUrl, value.trim() === '' ? {} : { q: value.trim() }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);

        return () => clearTimeout(timer);
    }, [value, routeUrl]);

    return (
        <div className={cn('relative', className)}>
            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2" />
            <Input value={value} onChange={(e) => setValue(e.target.value)} placeholder={placeholder} className="h-8 pl-8 text-sm" />
            {value !== '' && (
                <button
                    type="button"
                    onClick={() => setValue('')}
                    className="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2"
                    aria-label="Bersihkan pencarian"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            )}
        </div>
    );
}

/* ================================================================= paginasi */

export function ServerPagination<T>({ page }: { page: Paginated<T> }) {
    if (page.total === 0) return null;

    const pergi = (url: string | null) => {
        if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    const sebelumnya = page.links[0]?.url ?? null;
    const berikutnya = page.links[page.links.length - 1]?.url ?? null;

    return (
        <div className="flex items-center justify-between border-t px-4 py-2.5">
            <p className="text-muted-foreground tabular text-xs">
                {page.from}–{page.to} dari {page.total} baris
            </p>
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    disabled={page.current_page <= 1}
                    onClick={() => pergi(sebelumnya)}
                    aria-label="Halaman sebelumnya"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                </Button>
                <span className="tabular px-1 font-mono text-xs">
                    {page.current_page} / {page.last_page}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    disabled={page.current_page >= page.last_page}
                    onClick={() => pergi(berikutnya)}
                    aria-label="Halaman berikutnya"
                >
                    <ChevronRight className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}

/* =============================================================== form field */

export function Field({
    label,
    error,
    hint,
    required,
    className,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label className="text-xs font-medium">
                {label}
                {required && <span className="text-destructive ml-0.5">*</span>}
            </Label>
            {children}
            {hint && !error && <p className="text-2xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-2xs text-destructive font-medium">{error}</p>}
        </div>
    );
}

/** Select bergaya sama dengan Input; memakai Radix agar dropdown selalu terbaca (gelap/terang). */
export function NativeSelect({
    value,
    onChange,
    options,
    placeholder,
    className,
    disabled = false,
}: {
    value: string | number | null;
    onChange: (v: string) => void;
    options: { value: string | number; label: string }[];
    placeholder?: string;
    className?: string;
    disabled?: boolean;
}) {
    const current = value === null ? '' : String(value);
    const hasPlaceholder = Boolean(placeholder);
    // Radix menolak SelectItem dengan value string kosong; placeholder diberi nilai sentinel.
    const EMPTY = '__EMPTY__';
    const selected = hasPlaceholder && current === '' ? EMPTY : current;

    return (
        <Select value={selected} onValueChange={(v) => onChange(hasPlaceholder && v === EMPTY ? '' : v)} disabled={disabled}>
            <SelectTrigger
                className={cn(
                    'border-input bg-background focus:ring-ring h-8 w-full gap-1.5 rounded-md border px-2 py-0 text-sm shadow-xs focus:ring-2 focus:ring-offset-2',
                    className,
                )}
            >
                <SelectValue placeholder={placeholder || 'Pilih...'} />
            </SelectTrigger>
            <SelectContent>
                {hasPlaceholder && (
                    <SelectItem value={EMPTY} className="text-muted-foreground">
                        {placeholder}
                    </SelectItem>
                )}
                {options.map((o) => (
                    <SelectItem key={o.value} value={String(o.value)}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/* ================================================================ penanda */

export function ActiveBadge({ active }: { active: boolean }) {
    return (
        <span
            className={cn(
                'text-2xs inline-flex items-center rounded px-1.5 py-0.5 font-semibold ring-1 ring-inset',
                active ? 'bg-success/10 text-success ring-success/20' : 'bg-muted text-muted-foreground ring-border',
            )}
        >
            {active ? 'Aktif' : 'Nonaktif'}
        </span>
    );
}

/** Penanda netral untuk tipe lokasi, peran, dan sejenisnya. */
export function Chip({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'accent' | 'warning' }) {
    return (
        <span
            className={cn(
                'text-2xs inline-flex items-center rounded px-1.5 py-0.5 font-semibold ring-1 ring-inset',
                tone === 'accent' && 'bg-primary/10 text-primary ring-primary/20',
                tone === 'warning' && 'bg-warning/15 text-warning-foreground ring-warning/30 dark:text-warning',
                tone === 'neutral' && 'bg-muted text-muted-foreground ring-border',
            )}
        >
            {children}
        </span>
    );
}

/* ============================================================ pesan flash */

/** Menampilkan flash success/error dari server, hilang sendiri setelah 4 detik. */
export function FlashMessage() {
    const { flash } = usePage().props as unknown as {
        flash?: { success?: string | null; error?: string | null };
    };
    const [tampil, setTampil] = useState(true);
    const pesan = flash?.success ?? flash?.error ?? null;
    const gagal = Boolean(flash?.error);

    useEffect(() => {
        setTampil(true);

        if (!pesan) return;

        const timer = setTimeout(() => setTampil(false), 4000);

        return () => clearTimeout(timer);
    }, [pesan]);

    if (!pesan || !tampil) return null;

    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                gagal ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'border-success/30 bg-success/10 text-success',
            )}
        >
            {gagal ? <X className="mt-0.5 h-3.5 w-3.5 shrink-0" /> : <Check className="mt-0.5 h-3.5 w-3.5 shrink-0" />}
            <span className="flex-1">{pesan}</span>
            <button type="button" onClick={() => setTampil(false)} aria-label="Tutup pesan">
                <X className="h-3.5 w-3.5 opacity-60 hover:opacity-100" />
            </button>
        </div>
    );
}

/* ======================================================= konfirmasi hapus */

/** Tombol aksi kecil untuk mengubah baris — ikon pensil. */
export function EditButton({ onClick, label = 'Ubah' }: { onClick: () => void; label?: string }) {
    return (
        <Button
            variant="ghost"
            size="icon"
            className="text-muted-foreground hover:text-foreground h-7 w-7"
            onClick={onClick}
            aria-label={label}
            title={label}
        >
            <Pencil className="h-3.5 w-3.5" />
        </Button>
    );
}

/**
 * Tombol hapus berupa ikon tempat sampah; setiap penghapusan wajib melalui
 * dialog konfirmasi agar tidak ada data yang terhapus tanpa sengaja.
 */
export function DeleteButton({
    onConfirm,
    label = 'Hapus',
    disabled = false,
    disabledReason,
}: {
    onConfirm: () => void;
    label?: string;
    /** Dipakai untuk master yang sudah terpakai — tombolnya tetap terlihat agar jelas mengapa tidak bisa dihapus. */
    disabled?: boolean;
    disabledReason?: string;
}) {
    const [terbuka, setTerbuka] = useState(false);

    return (
        <>
            <Button
                variant="ghost"
                size="icon"
                className="text-muted-foreground hover:text-destructive h-7 w-7"
                onClick={() => setTerbuka(true)}
                disabled={disabled}
                aria-label={label}
                title={disabled ? (disabledReason ?? label) : label}
            >
                <Trash2 className="h-3.5 w-3.5" />
            </Button>
            <Dialog open={terbuka} onOpenChange={setTerbuka}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Hapus data ini?</DialogTitle>
                        <DialogDescription>Tindakan ini tidak bisa dibatalkan.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setTerbuka(false)}>
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => {
                                setTerbuka(false);
                                onConfirm();
                            }}
                        >
                            {label}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
