<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\CashDifference;
use App\Models\FiscalClosing;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodReopenRequest;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\CashDifferenceResolver;
use App\Services\FiscalPeriodCloser;
use App\Services\FiscalPeriodManager;
use App\Services\FiscalPeriodReopener;
use App\Services\HppRecalculator;
use App\Services\InitialOpeningPoster;
use App\Services\PreClosingValidator;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Periode pembukuan: membuat, membuka, menutup, dan membuka ulang
 * (PRD 20-25, Addendum 40, 43-46).
 *
 * Seluruh layar dibatasi OWNER/SUPER ADMIN. Menutup periode membekukan angka
 * yang dipakai seluruh laporan; MANAGER pun tidak diberi kunci ini, sesuai
 * matriks peran Addendum 50. Menyetujui buka ulang bahkan lebih sempit lagi —
 * hanya OWNER — dan itu ditegakkan di FiscalPeriodReopener.
 */
class FiscalPeriodController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN];

    public function __construct(
        private FiscalPeriodManager $manager,
        private InitialOpeningPoster $opening,
        private PreClosingValidator $validator,
        private FiscalPeriodCloser $closer,
        private FiscalPeriodReopener $reopener,
        private CashDifferenceResolver $differences,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizeAkses($request);
        $branch = ActiveBranch::for($user);

        $periods = FiscalPeriod::where('owner_id', $user->owner_id)
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branch->branch_id)->orWhereNull('branch_id')))
            ->with(['branch', 'closedByUser'])
            ->orderByDesc('start_date')
            ->get();

        $snapshots = FiscalClosing::whereIn('fiscal_period_id', $periods->pluck('fiscal_period_id'))
            ->where('status', FiscalClosing::STATUS_VALID)
            ->get()
            ->keyBy('fiscal_period_id');

        // Yang sedang berjalan: periode terbuka pertama, kalau tidak ada
        // ambil draf pertama supaya alur "buat -> isi opening -> buka" terlihat.
        $aktif = $periods->firstWhere('status', FiscalPeriod::STATUS_OPEN)
            ?? $periods->firstWhere('status', FiscalPeriod::STATUS_REOPENED)
            ?? $periods->firstWhere('status', FiscalPeriod::STATUS_DRAFT)
            ?? $periods->first();

        return Inertia::render('periode/index', [
            'periods' => $periods->map(fn (FiscalPeriod $p) => $this->ringkas($p, $snapshots->get($p->fiscal_period_id)))->values(),
            'active' => $aktif === null ? null : $this->rinci($aktif, $snapshots->get($aktif->fiscal_period_id)),
            'requests' => $this->permintaan($user),
            'differences' => $this->selisihKas($user, $branch?->branch_id),
            'products' => $aktif !== null && $aktif->status === FiscalPeriod::STATUS_DRAFT && $branch !== null
                ? $this->products($user->owner_id, $branch->branch_id)
                : [],
            'branches' => $user->accessibleBranches()->map(fn ($b) => ['id' => $b->branch_id, 'name' => $b->branch_name])->values(),
            'periodDefaults' => $this->saranRentang($user),
            'activeBranch' => $branch === null ? null : ['id' => $branch->branch_id, 'name' => $branch->branch_name],
            'canApprove' => $user->role === User::ROLE_OWNER,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizeAkses($request);

        // Hanya rentang tanggalnya yang diminta; kode dan nama dibuat sistem
        // (lihat FiscalPeriodManager).
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
        ], [], [
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
        ]);

        $period = $this->manager->buat($user, $data);

        return back()->with('success', "Periode {$period->period_code} ({$period->period_name}) dibuat sebagai draf. Isi saldo awalnya bila ini periode pertama, lalu buka.");
    }

    /** Saldo awal periode pertama (Addendum 40). */
    public function opening(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric'],
            'lines.*.unit_cost' => ['required', 'numeric'],
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'opening_receivable' => ['nullable', 'numeric', 'min:0'],
            'opening_payable' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'lines' => 'stok awal',
            'opening_cash' => 'kas awal',
        ]);

        $this->opening->simpan($user, $period, $branch, $data);

        return back()->with('success', "Saldo awal {$period->period_code} tersimpan. Periode siap dibuka.");
    }

    public function buka(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        $this->manager->buka($user, $period);

        return back()->with('success', "Periode {$period->period_code} dibuka dan siap menerima transaksi.");
    }

    public function destroy(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        $this->manager->hapus($user, $period);

        return back()->with('success', "Periode draf {$period->period_code} dihapus.");
    }

    /** Hitung ulang HPP rata-rata atas permintaan (matriks peran Addendum 50: Pemilik & Super Admin). */
    public function hitungUlangHpp(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        if (! in_array($period->status, ['OPEN', 'REOPENED'], true)) {
            throw ValidationException::withMessages([
                'period' => 'HPP hanya dihitung ulang pada periode yang masih terbuka. Periode tertutup harus dibuka ulang lebih dulu.',
            ]);
        }

        if ($period->costing_method === 'FIFO') {
            throw ValidationException::withMessages([
                'period' => 'Periode ini memakai FIFO: HPP-nya diambil dari lot yang terpakai sehingga tidak perlu dihitung ulang.',
            ]);
        }

        $branchIds = $period->branch_id !== null
            ? [$period->branch_id]
            : $user->accessibleBranches()->pluck('branch_id')->all();

        $nota = 0;
        $selisih = 0.0;

        foreach ($branchIds as $branchId) {
            $hasil = DB::transaction(fn () => app(HppRecalculator::class)->recalculate($period, $branchId, null, HppRecalculator::TRIGGER_MANUAL, $user));
            $nota += $hasil['movements_changed'];
            $selisih += $hasil['new_cogs_value'] - $hasil['old_cogs_value'];
        }

        return back()->with('success', $nota === 0
            ? 'HPP sudah sesuai — tidak ada pergerakan stok yang perlu diperbaiki.'
            : sprintf('HPP dihitung ulang: %d pergerakan diperbaiki, HPP penjualan bergeser Rp%s.', $nota, number_format($selisih, 0, ',', '.')));
    }

    public function tutup(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        $data = $request->validate([
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['string'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $disetujui = array_values(array_intersect($data['overrides'] ?? [], PreClosingValidator::OVERRIDES));

        // Menembus blokir pra-tutup adalah wewenang pemilik, bukan sekadar
        // wewenang "boleh menutup periode" (Addendum 55.3).
        if ($disetujui !== [] && $user->role !== User::ROLE_OWNER) {
            throw new HttpException(403, 'Hanya Pemilik yang dapat menutup periode dengan menembus hasil pemeriksaan.');
        }

        $closing = $this->closer->tutup($user, $period, $disetujui, $data['override_reason'] ?? null);

        return back()->with('success', "Periode {$period->period_code} ditutup. Snapshot versi {$closing->version} tersimpan.");
    }

    public function ajukanBuka(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $period);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            [],
            ['reason' => 'alasan']
        );

        $this->reopener->ajukan($user, $period, $data['reason']);

        return back()->with('success', "Permintaan buka ulang {$period->period_code} menunggu persetujuan pemilik.");
    }

    public function setujuiBuka(Request $request, FiscalPeriodReopenRequest $reopenRequest): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $reopenRequest->period);

        $period = $this->reopener->setujui($user, $reopenRequest);

        return back()->with('success', "Periode {$period->period_code} dibuka kembali dan wajib ditutup ulang.");
    }

    public function tolakBuka(Request $request, FiscalPeriodReopenRequest $reopenRequest): RedirectResponse
    {
        $user = $this->authorizeAkses($request);
        $this->authorizeMilik($user, $reopenRequest->period);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->reopener->tolak($user, $reopenRequest, $data['note'] ?? null);

        return back()->with('success', 'Permintaan buka ulang ditolak.');
    }

    /** Penyelesaian selisih kas shift (Addendum 46). */
    public function selesaikanSelisih(Request $request, CashDifference $difference): RedirectResponse
    {
        $user = $request->user();

        // Lebih longgar daripada layar periodenya: MANAGER memang berwenang
        // menyelesaikan selisih kas timnya (Addendum 46.2).
        if (! in_array($user->role, [...self::PERAN_BERHAK, User::ROLE_MANAGER], true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat menyelesaikan selisih kas.');
        }

        abort_unless($difference->branch->owner_id === $user->owner_id, 404);

        $data = $request->validate([
            'resolution_type' => ['required', 'string'],
            'note' => ['required', 'string', 'max:500'],
        ], [], [
            'resolution_type' => 'cara penyelesaian',
            'note' => 'alasan',
        ]);

        $this->differences->selesaikan($user, $difference, $data['resolution_type'], $data['note']);

        return back()->with('success', 'Selisih kas diselesaikan dan buku kas disamakan dengan uang fisik.');
    }

    /**
     * Saran rentang tanggal per scope, siap dipakai formulir periode baru.
     *
     * Dikirim untuk SETIAP pilihan lokasi sekaligus, bukan hanya lokasi aktif:
     * mengganti pilihan lokasi di formulir harus langsung memperbarui
     * tanggalnya, dan itu tidak layak menunggu satu permintaan ke server.
     * Kuncinya string kosong untuk periode tingkat usaha.
     *
     * @return array<string, array{startDate: string, endDate: string}>
     */
    private function saranRentang(User $user): array
    {
        $saran = ['' => $this->manager->saranRentang($user->owner_id, null)];

        foreach ($user->accessibleBranches() as $branch) {
            $saran[(string) $branch->branch_id] = $this->manager->saranRentang($user->owner_id, $branch->branch_id);
        }

        return $saran;
    }

    private function authorizeAkses(Request $request): User
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik dan Super Admin yang dapat mengelola periode pembukuan.');
        }

        return $user;
    }

    private function authorizeMilik(User $user, FiscalPeriod $period): void
    {
        abort_unless($period->owner_id === $user->owner_id, 404);
    }

    /** @return array<string, mixed> */
    private function ringkas(FiscalPeriod $period, ?FiscalClosing $snapshot): array
    {
        return [
            'id' => $period->fiscal_period_id,
            'code' => $period->period_code,
            'name' => $period->period_name,
            'branch' => $period->branch?->branch_name,
            'startDate' => $period->start_date->toDateString(),
            'endDate' => $period->end_date->toDateString(),
            'status' => $period->status,
            'costingMethod' => $period->costing_method,
            'closedAt' => $period->closed_at?->toDateString(),
            'closedBy' => $period->closedByUser?->full_name,
            'netSales' => $snapshot === null ? null : (float) $snapshot->net_sales,
            'cogs' => $snapshot === null ? null : (float) $snapshot->cogs_value,
            'grossProfit' => $snapshot === null ? null : (float) $snapshot->gross_profit,
            'netProfit' => $snapshot === null ? null : (float) $snapshot->net_profit,
            'snapshotVersion' => $snapshot?->version,
        ];
    }

    /** @return array<string, mixed> */
    private function rinci(FiscalPeriod $period, ?FiscalClosing $snapshot): array
    {
        $checks = $this->validator->check($period);

        return $this->ringkas($period, $snapshot) + [
            'checks' => $checks,
            'openingSaved' => $this->opening->sudahTersimpan($period),
            'isFirst' => $period->sebelumnya() === null,
            'affected' => $this->reopener->terdampak($period)
                ->map(fn (FiscalPeriod $p) => ['code' => $p->period_code, 'status' => $p->status])
                ->values(),
            'snapshot' => $snapshot === null ? null : [
                'version' => $snapshot->version,
                'openingInventory' => (float) $snapshot->opening_inventory_value,
                'purchase' => (float) $snapshot->purchase_value,
                'purchaseReturn' => (float) $snapshot->purchase_return_value,
                'salesCost' => (float) $snapshot->sales_cost_value,
                'salesReturnCost' => (float) $snapshot->sales_return_cost_value,
                'adjustment' => (float) $snapshot->adjustment_value,
                'endingInventory' => (float) $snapshot->ending_inventory_value,
                'grossSales' => (float) $snapshot->gross_sales,
                'netSales' => (float) $snapshot->net_sales,
                'cogs' => (float) $snapshot->cogs_value,
                'grossProfit' => (float) $snapshot->gross_profit,
                'expense' => (float) $snapshot->expense_total,
                'netProfit' => (float) $snapshot->net_profit,
                'openingCash' => (float) $snapshot->opening_cash,
                'cashSales' => (float) $snapshot->cash_sales,
                'cashIn' => (float) $snapshot->cash_in,
                'cashOut' => (float) $snapshot->cash_out,
                'refund' => (float) $snapshot->refund,
                'endingCash' => (float) $snapshot->ending_cash,
                'cashDifference' => (float) $snapshot->cash_difference,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function permintaan(User $user): array
    {
        return FiscalPeriodReopenRequest::whereHas('period', fn ($q) => $q->where('owner_id', $user->owner_id))
            ->with(['period', 'requester', 'reviewer'])
            ->orderByDesc('reopen_request_id')
            ->limit(20)
            ->get()
            ->map(fn (FiscalPeriodReopenRequest $r) => [
                'id' => $r->reopen_request_id,
                'periodCode' => $r->period->period_code,
                'reason' => $r->reason,
                'status' => $r->status,
                'approvalType' => $r->approval_type,
                'requestedBy' => $r->requester?->full_name,
                'requestedAt' => $r->created_at?->toDateTimeString(),
                'reviewedBy' => $r->reviewer?->full_name,
                'isMine' => $r->requested_by === $user->user_id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function selisihKas(User $user, ?int $branchId): array
    {
        return CashDifference::whereHas('branch', fn ($q) => $q->where('owner_id', $user->owner_id))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['shift.cashier', 'resolver', 'period'])
            ->orderByRaw("status = 'RESOLVED'")
            ->orderByDesc('difference_id')
            ->limit(30)
            ->get()
            ->map(fn (CashDifference $d) => [
                'id' => $d->difference_id,
                'periodCode' => $d->period?->period_code,
                'shiftNumber' => $d->shift?->shift_number,
                'cashier' => $d->shift?->cashier?->full_name,
                'expected' => (float) $d->expected_cash,
                'actual' => (float) $d->actual_cash,
                'difference' => (float) $d->difference,
                'status' => $d->status,
                'resolutionType' => $d->resolution_type,
                'resolvedBy' => $d->resolver?->full_name,
                'note' => $d->note,
                'isMine' => $d->shift?->cashier_id === $user->user_id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function products(int $ownerId, int $branchId): array
    {
        $balances = StockBalance::where('branch_id', $branchId)->get()->keyBy('product_id');

        return Product::where('owner_id', $ownerId)
            ->active()
            ->stocked()
            ->with(['uoms' => fn ($q) => $q->where('active', true)])
            ->orderBy('product_name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->product_id,
                'name' => $p->product_name,
                'sku' => $p->sku,
                'isWeighted' => (bool) $p->is_weighted,
                'stock' => (float) ($balances->get($p->product_id)?->qty_on_hand ?? 0),
                'uoms' => $p->uoms->map(fn ($u) => ['id' => $u->uom_id, 'name' => $u->uom_name, 'factor' => (float) $u->conversion_factor])->values(),
            ])
            ->all();
    }
}
