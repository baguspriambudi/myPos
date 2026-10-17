<?php

use App\Http\Controllers\Banking\ExpenseController;
use App\Http\Controllers\Banking\FiscalPeriodController;
use App\Http\Controllers\Banking\PayableController;
use App\Http\Controllers\Banking\ReceivableController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Kasir\CashierShiftController;
use App\Http\Controllers\Kasir\KasirController;
use App\Http\Controllers\Laporan\CashBookController;
use App\Http\Controllers\Laporan\InventoryReportController;
use App\Http\Controllers\Laporan\ProfitLossController;
use App\Http\Controllers\Laporan\PurchaseReportController;
use App\Http\Controllers\Laporan\SalesReportController;
use App\Http\Controllers\Master\BranchController;
use App\Http\Controllers\Master\ChartOfAccountController;
use App\Http\Controllers\Master\CustomerController;
use App\Http\Controllers\Master\DiscountController;
use App\Http\Controllers\Master\ExpenseCategoryController;
use App\Http\Controllers\Master\OwnerSettingController;
use App\Http\Controllers\Master\PriceListController;
use App\Http\Controllers\Master\ProductCategoryController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Master\TaxController;
use App\Http\Controllers\Master\UnitController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Pembelian\GoodsReceiptController;
use App\Http\Controllers\Pembelian\PurchaseInvoiceController;
use App\Http\Controllers\Pembelian\PurchaseReturnController;
use App\Http\Controllers\Penjualan\SaleController;
use App\Http\Controllers\Penjualan\SalesReturnController;
use App\Http\Controllers\Persediaan\StockAdjustmentController;
use App\Http\Controllers\Persediaan\StockCardController;
use App\Http\Controllers\Persediaan\StockOpnameController;
use App\Http\Controllers\Persediaan\StockTransferController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    /*
    |----------------------------------------------------------------------
    | Master data
    |----------------------------------------------------------------------
    | Seluruhnya sudah memakai database. Menulis dibatasi OWNER/SUPER ADMIN
    | di MasterController::authorizeWrite(); membaca terbuka untuk peran lain
    | supaya layar transaksi dapat memilih data.
    */
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('usaha', [OwnerSettingController::class, 'edit'])->name('usaha.edit');
        Route::put('usaha', [OwnerSettingController::class, 'update'])->name('usaha.update');

        Route::get('lokasi', [BranchController::class, 'index'])->name('lokasi.index');
        Route::post('lokasi', [BranchController::class, 'store'])->name('lokasi.store');
        Route::put('lokasi/{branch}', [BranchController::class, 'update'])->name('lokasi.update');
        Route::delete('lokasi/{branch}', [BranchController::class, 'destroy'])->name('lokasi.destroy');

        Route::get('pengguna', [UserController::class, 'index'])->name('pengguna.index');
        Route::post('pengguna', [UserController::class, 'store'])->name('pengguna.store');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->name('pengguna.update');
        Route::delete('pengguna/{user}', [UserController::class, 'destroy'])->name('pengguna.destroy');

        Route::get('kategori', [ProductCategoryController::class, 'index'])->name('kategori.index');
        Route::post('kategori', [ProductCategoryController::class, 'store'])->name('kategori.store');
        Route::put('kategori/{category}', [ProductCategoryController::class, 'update'])->name('kategori.update');
        Route::delete('kategori/{category}', [ProductCategoryController::class, 'destroy'])->name('kategori.destroy');

        // Nama satuan dikelola di sini; layar produk hanya memilih dari daftar ini.
        Route::get('satuan', [UnitController::class, 'index'])->name('satuan.index');
        Route::post('satuan', [UnitController::class, 'store'])->name('satuan.store');
        Route::put('satuan/{unit}', [UnitController::class, 'update'])->name('satuan.update');
        Route::delete('satuan/{unit}', [UnitController::class, 'destroy'])->name('satuan.destroy');

        Route::get('produk', [ProductController::class, 'index'])->name('produk.index');
        Route::post('produk', [ProductController::class, 'store'])->name('produk.store');
        Route::put('produk/{product}', [ProductController::class, 'update'])->name('produk.update');
        Route::delete('produk/{product}', [ProductController::class, 'destroy'])->name('produk.destroy');

        // Daftar harga jual bertingkat (Addendum 39.10)
        Route::get('harga', [PriceListController::class, 'index'])->name('harga.index');
        Route::post('harga', [PriceListController::class, 'store'])->name('harga.store');
        Route::get('harga/{priceList}', [PriceListController::class, 'show'])->name('harga.show');
        Route::put('harga/{priceList}', [PriceListController::class, 'update'])->name('harga.update');
        Route::delete('harga/{priceList}', [PriceListController::class, 'destroy'])->name('harga.destroy');
        Route::post('harga/{priceList}/tingkat', [PriceListController::class, 'storeItem'])->name('harga.tingkat.store');
        Route::put('harga/{priceList}/tingkat/{item}', [PriceListController::class, 'updateItem'])->name('harga.tingkat.update');
        Route::delete('harga/{priceList}/tingkat/{item}', [PriceListController::class, 'destroyItem'])->name('harga.tingkat.destroy');

        Route::get('pelanggan', [CustomerController::class, 'index'])->name('pelanggan.index');
        Route::post('pelanggan', [CustomerController::class, 'store'])->name('pelanggan.store');
        Route::put('pelanggan/{customer}', [CustomerController::class, 'update'])->name('pelanggan.update');
        Route::delete('pelanggan/{customer}', [CustomerController::class, 'destroy'])->name('pelanggan.destroy');

        Route::get('pemasok', [SupplierController::class, 'index'])->name('pemasok.index');
        Route::post('pemasok', [SupplierController::class, 'store'])->name('pemasok.store');
        Route::put('pemasok/{supplier}', [SupplierController::class, 'update'])->name('pemasok.update');
        Route::delete('pemasok/{supplier}', [SupplierController::class, 'destroy'])->name('pemasok.destroy');

        Route::get('pajak', [TaxController::class, 'index'])->name('pajak.index');
        Route::post('pajak', [TaxController::class, 'store'])->name('pajak.store');
        Route::put('pajak/{tax}', [TaxController::class, 'update'])->name('pajak.update');
        Route::delete('pajak/{tax}', [TaxController::class, 'destroy'])->name('pajak.destroy');

        Route::get('diskon', [DiscountController::class, 'index'])->name('diskon.index');
        Route::post('diskon', [DiscountController::class, 'store'])->name('diskon.store');
        Route::put('diskon/{discount}', [DiscountController::class, 'update'])->name('diskon.update');
        Route::delete('diskon/{discount}', [DiscountController::class, 'destroy'])->name('diskon.destroy');

        Route::get('kategori-biaya', [ExpenseCategoryController::class, 'index'])->name('kategori-biaya.index');
        Route::post('kategori-biaya', [ExpenseCategoryController::class, 'store'])->name('kategori-biaya.store');
        Route::put('kategori-biaya/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('kategori-biaya.update');
        Route::delete('kategori-biaya/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('kategori-biaya.destroy');

        Route::get('akun', [ChartOfAccountController::class, 'index'])->name('akun.index');
        Route::post('akun', [ChartOfAccountController::class, 'store'])->name('akun.store');
        Route::put('akun/{account}', [ChartOfAccountController::class, 'update'])->name('akun.update');
        Route::delete('akun/{account}', [ChartOfAccountController::class, 'destroy'])->name('akun.destroy');
    });

    Route::post('lokasi-aktif', BranchSwitchController::class)->name('branch.switch');

    /*
    |----------------------------------------------------------------------
    | Halaman operasional
    |----------------------------------------------------------------------
    | Seluruhnya sudah memakai database sejak 6 September 2026; tidak ada lagi
    | layar berdata contoh, dan resources/js/data/dummy.ts sudah dihapus.
    */
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Penjualan dari layar kasir (Addendum 51.2, 51.4, 52).
    Route::get('kasir', [KasirController::class, 'index'])->name('kasir.index');
    Route::post('kasir/nota', [KasirController::class, 'store'])->name('kasir.nota.store');
    Route::post('kasir/hitung', [KasirController::class, 'hitung'])->name('kasir.hitung');
    // Shift bukan prasyarat berjualan — lihat SalePoster. Sekadar menaut
    // transaksi yang terjadi selama ia terbuka supaya bisa direkonsiliasi.
    Route::post('kasir/shift/buka', [CashierShiftController::class, 'buka'])->name('kasir.shift.buka');
    Route::post('kasir/shift/{shift}/tutup', [CashierShiftController::class, 'tutup'])->name('kasir.shift.tutup');
    // Daftar nota + pembatalan. Void mengembalikan stok ke lot asalnya dan
    // membatalkan pembayarannya (Addendum 41).
    Route::get('penjualan', [SaleController::class, 'index'])->name('penjualan.index');
    Route::post('penjualan/{sale}/batal', [SaleController::class, 'void'])->name('penjualan.batal');
    // Retur mengacu ke nota, memakai fiscal period TANGGAL RETUR — boleh
    // lintas period bahkan setelah period nota CLOSED (Addendum 48).
    Route::get('retur/penjualan', [SalesReturnController::class, 'index'])->name('retur.penjualan.index');
    Route::post('retur/penjualan', [SalesReturnController::class, 'store'])->name('retur.penjualan.store');
    Route::post('retur/penjualan/{salesReturn}/batal', [SalesReturnController::class, 'void'])->name('retur.penjualan.batal');

    // Barang datang dulu, fakturnya menyusul — dua dokumen terpisah, dan hanya
    // penerimaan yang menambah stok (Addendum 58).
    Route::get('penerimaan', [GoodsReceiptController::class, 'index'])->name('penerimaan.index');
    Route::post('penerimaan', [GoodsReceiptController::class, 'store'])->name('penerimaan.store');
    Route::post('penerimaan/{goodsReceipt}/batal', [GoodsReceiptController::class, 'void'])->name('penerimaan.batal');

    Route::get('pembelian', [PurchaseInvoiceController::class, 'index'])->name('pembelian.index');
    Route::post('pembelian', [PurchaseInvoiceController::class, 'store'])->name('pembelian.store');
    Route::post('pembelian/{purchase}/batal', [PurchaseInvoiceController::class, 'void'])->name('pembelian.batal');

    // Retur pembelian mengacu ke PENERIMAAN, bukan faktur — lot lahir dari
    // penerimaan (Addendum 58.6).
    Route::get('retur/pembelian', [PurchaseReturnController::class, 'index'])->name('retur.pembelian.index');
    Route::post('retur/pembelian', [PurchaseReturnController::class, 'store'])->name('retur.pembelian.store');
    Route::post('retur/pembelian/{purchaseReturn}/batal', [PurchaseReturnController::class, 'void'])->name('retur.pembelian.batal');
    // Kartu stok: daftar campuran seluruh produk, atau kartu satu produk
    // dengan saldo berjalan bila ?produk= diisi.
    Route::get('stok', [StockCardController::class, 'index'])->name('stok.index');

    // Penyesuaian stok: satu-satunya cara mengubah saldo tanpa dokumen
    // jual-beli, karena itu dibatasi MANAJER ke atas (Addendum 50).
    Route::get('stok/penyesuaian', [StockAdjustmentController::class, 'index'])->name('stok.penyesuaian.index');
    Route::post('stok/penyesuaian', [StockAdjustmentController::class, 'store'])->name('stok.penyesuaian.store');

    // Transfer langsung, tanpa state IN_TRANSIT di tahap ini (Addendum 49.2).
    Route::get('stok/transfer', [StockTransferController::class, 'index'])->name('stok.transfer.index');
    Route::post('stok/transfer', [StockTransferController::class, 'store'])->name('stok.transfer.store');
    Route::post('stok/transfer/{stockTransfer}/batal', [StockTransferController::class, 'void'])->name('stok.transfer.batal');

    // Hitung fisik (DRAFT, semua peran) lalu terapkan (MANAJER ke atas) —
    // menerapkan membuat StockAdjustment, bukan movement sendiri.
    Route::get('stok/opname', [StockOpnameController::class, 'index'])->name('stok.opname.index');
    Route::post('stok/opname', [StockOpnameController::class, 'store'])->name('stok.opname.store');
    Route::post('stok/opname/{stockOpname}/terapkan', [StockOpnameController::class, 'terapkan'])->name('stok.opname.terapkan');
    Route::post('stok/opname/{stockOpname}/batal', [StockOpnameController::class, 'batal'])->name('stok.opname.batal');

    // Biaya CASH menulis cash_transactions (Addendum 60.2) — jalur pertama di
    // aplikasi yang menyentuh buku kas; penjualan tunai belum menulis ke sana.
    Route::get('biaya', [ExpenseController::class, 'index'])->name('biaya.index');
    Route::post('biaya', [ExpenseController::class, 'store'])->name('biaya.store');
    Route::post('biaya/{expense}/batal', [ExpenseController::class, 'void'])->name('biaya.batal');
    // Lahir otomatis saat faktur pemasok diposting (Addendum 58.3). Pembayaran
    // CASH menulis cash_transactions, sama seperti biaya operasional.
    Route::get('utang', [PayableController::class, 'index'])->name('utang.index');
    Route::post('utang/{payable}/bayar', [PayableController::class, 'bayar'])->name('utang.bayar');
    Route::post('utang/pembayaran/{payment}/batal', [PayableController::class, 'batalBayar'])->name('utang.pembayaran.batal');

    // Lahir dari nota kasir berbayar kredit (Addendum 41.3).
    Route::get('piutang', [ReceivableController::class, 'index'])->name('piutang.index');
    Route::post('piutang/{receivable}/terima', [ReceivableController::class, 'terima'])->name('piutang.terima');
    Route::post('piutang/pembayaran/{payment}/batal', [ReceivableController::class, 'batalTerima'])->name('piutang.pembayaran.batal');
    // Periode pembukuan: satu-satunya tempat angka period dibekukan jadi
    // snapshot resmi (Addendum 40, 43-46). Menutup mengunci seluruh transaksi
    // di dalamnya; membukanya kembali butuh permintaan + persetujuan pemilik.
    Route::get('periode', [FiscalPeriodController::class, 'index'])->name('periode.index');
    Route::post('periode', [FiscalPeriodController::class, 'store'])->name('periode.store');
    Route::post('periode/{period}/opening', [FiscalPeriodController::class, 'opening'])->name('periode.opening');
    Route::post('periode/{period}/buka', [FiscalPeriodController::class, 'buka'])->name('periode.buka');
    Route::delete('periode/{period}', [FiscalPeriodController::class, 'destroy'])->name('periode.destroy');
    Route::get('periode/{period}/jurnal', [FiscalPeriodController::class, 'jurnal'])->name('periode.jurnal');
    Route::post('periode/{period}/hitung-ulang-hpp', [FiscalPeriodController::class, 'hitungUlangHpp'])->name('periode.hitung-ulang-hpp');
    Route::post('periode/{period}/tutup', [FiscalPeriodController::class, 'tutup'])->name('periode.tutup');
    Route::post('periode/{period}/buka-ulang', [FiscalPeriodController::class, 'ajukanBuka'])->name('periode.buka-ulang');
    Route::post('periode/buka-ulang/{reopenRequest}/setujui', [FiscalPeriodController::class, 'setujuiBuka'])->name('periode.buka-ulang.setujui');
    Route::post('periode/buka-ulang/{reopenRequest}/tolak', [FiscalPeriodController::class, 'tolakBuka'])->name('periode.buka-ulang.tolak');
    Route::post('periode/selisih-kas/{difference}/selesaikan', [FiscalPeriodController::class, 'selesaikanSelisih'])->name('periode.selisih.selesaikan');
    /*
    |----------------------------------------------------------------------
    | Laporan
    |----------------------------------------------------------------------
    | Disusun per modul mengikuti sidebar. Laba rugi mengambil angka dari
    | SNAPSHOT bila periodenya sudah ditutup — lihat FiscalPeriodFigures.
    */
    Route::redirect('laporan', '/laporan/laba-rugi')->name('laporan.index');
    Route::get('laporan/penjualan', [SalesReportController::class, 'index'])->name('laporan.penjualan');
    Route::get('laporan/pembelian', [PurchaseReportController::class, 'index'])->name('laporan.pembelian');
    Route::get('laporan/persediaan', [InventoryReportController::class, 'index'])->name('laporan.persediaan');
    Route::get('laporan/laba-rugi', [ProfitLossController::class, 'index'])->name('laporan.laba-rugi');
    Route::get('laporan/kas', [CashBookController::class, 'index'])->name('laporan.kas');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
