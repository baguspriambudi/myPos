import { Chip } from '@/components/master-ui';
import { formatDate } from '@/components/pos-ui';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

/**
 * Bagian yang berulang di semua layar laporan.
 *
 * Pemilih periode dan kartu angka tampil sama persis di lima laporan; menyalin
 * markup-nya lima kali membuat perbaikan kecil harus dikerjakan lima kali juga.
 */

export interface PilihanPeriode {
    id: number;
    code: string;
    name: string;
    status: string;
    startDate: string;
    endDate: string;
}

const TONE_STATUS: Record<string, 'neutral' | 'accent' | 'warning'> = {
    DRAFT: 'neutral',
    OPEN: 'accent',
    CLOSING: 'warning',
    CLOSED: 'neutral',
    REOPENED: 'warning',
};

const LABEL_STATUS: Record<string, string> = {
    DRAFT: 'Draf',
    OPEN: 'Terbuka',
    CLOSING: 'Sedang Ditutup',
    CLOSED: 'Ditutup',
    REOPENED: 'Dibuka Ulang',
};

/** Pemilih periode; menavigasi ulang ke laporan yang sama dengan ?periode=. */
export function PemilihPeriode({
    routeUrl,
    periods,
    active,
}: {
    routeUrl: string;
    periods: PilihanPeriode[];
    active: { id: number; status: string; startDate: string; endDate: string } | null;
}) {
    if (periods.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="label-eyebrow">Periode</span>
            <div className="flex flex-wrap gap-1">
                {periods.map((p) => (
                    <button
                        key={p.id}
                        onClick={() => router.get(routeUrl, { periode: p.id }, { preserveState: true, preserveScroll: true })}
                        className={cn(
                            'rounded-md border px-2.5 py-1 font-mono text-xs transition-colors',
                            active?.id === p.id ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent',
                        )}
                    >
                        {p.code}
                    </button>
                ))}
            </div>
            {active && (
                <>
                    <Chip tone={TONE_STATUS[active.status] ?? 'neutral'}>{LABEL_STATUS[active.status] ?? active.status}</Chip>
                    <span className="text-muted-foreground text-xs">
                        {formatDate(active.startDate)} – {formatDate(active.endDate)}
                    </span>
                </>
            )}
        </div>
    );
}

/** Deret kartu angka ringkas di kepala laporan. */
export function KartuAngka({ items }: { items: { label: string; value: string; warn?: boolean; muted?: boolean }[] }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {items.map((k) => (
                <div key={k.label} className="bg-card rounded-lg border px-4 py-3">
                    <p className="label-eyebrow">{k.label}</p>
                    <p
                        className={cn(
                            'tabular mt-1 font-mono text-xl font-semibold',
                            k.warn && 'text-destructive',
                            k.muted && 'text-muted-foreground',
                        )}
                    >
                        {k.value}
                    </p>
                </div>
            ))}
        </div>
    );
}

/** Layar laporan tanpa satu pun periode: jangan tampilkan tabel kosong tanpa sebab. */
export function TanpaPeriode() {
    return (
        <div className="bg-card rounded-lg border px-4 py-8 text-center">
            <p className="text-muted-foreground text-sm">
                Belum ada periode pembukuan di lokasi ini, jadi belum ada yang bisa dilaporkan.
            </p>
        </div>
    );
}
