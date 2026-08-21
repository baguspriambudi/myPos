<?php

use App\Http\Controllers\BranchSwitchController;
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
use App\Http\Controllers\Master\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    | Tampilannya sengaja belum diubah. Angkanya masih dari data contoh di
    | resources/js/data/dummy.ts dan akan disambungkan ke database pada tahap
    | berikutnya, setelah modul transaksi dikerjakan.
    */
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::get('kasir', fn () => Inertia::render('kasir/index'))->name('kasir.index');
    Route::get('penjualan', fn () => Inertia::render('penjualan/index'))->name('penjualan.index');
    Route::get('pembelian', fn () => Inertia::render('pembelian/index'))->name('pembelian.index');
    Route::get('produk', fn () => Inertia::render('produk/index'))->name('produk.index');
    Route::get('stok', fn () => Inertia::render('stok/index'))->name('stok.index');
    Route::get('biaya', fn () => Inertia::render('biaya/index'))->name('biaya.index');
    Route::get('periode', fn () => Inertia::render('periode/index'))->name('periode.index');
    Route::get('laporan', fn () => Inertia::render('laporan/index'))->name('laporan.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
