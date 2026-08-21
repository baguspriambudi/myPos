/**
 * Data dummy sementara untuk pengembangan tampilan.
 * Bentuk datanya sengaja dibuat sama dengan rencana props dari Inertia,
 * jadi saat backend siap cukup ganti sumbernya tanpa mengubah komponen.
 *
 * Seluruh nilai uang dalam Rupiah penuh, qty dalam base unit.
 */

export type PeriodStatus = 'DRAFT' | 'OPEN' | 'CLOSING' | 'CLOSED' | 'REOPENED';
export type DocStatus = 'DRAFT' | 'POSTED' | 'VOID';
export type PaymentType = 'CASH' | 'QRIS' | 'DEBIT' | 'CREDIT_CARD' | 'TRANSFER' | 'E_WALLET';

/* ------------------------------------------------------------------ toko */

export const branch = {
    code: 'BR01',
    name: 'Sumber Makmur — Pusat',
    costingMethod: 'AVERAGE' as const,
    roundingUnit: 100,
    roundingMode: 'DOWN' as const,
};

export const activePeriod = {
    id: 3,
    code: 'FP-2026-08',
    name: 'Agustus 2026',
    startDate: '2026-08-01',
    endDate: '2026-08-31',
    status: 'OPEN' as PeriodStatus,
    day: 19,
    totalDays: 31,
};

/* ------------------------------------------------------------- dashboard */

export const kpi = {
    omzet: { value: 4_820_500, delta: 12.4, prev: 4_288_000 },
    transaksi: { value: 137, delta: 6.2, prev: 129 },
    hpp: { value: 3_412_700, delta: 9.8, prev: 3_108_400 },
    labaKotor: { value: 1_407_800, delta: 19.3, prev: 1_179_600 },
};

export const salesTrend: { date: string; omzet: number; hpp: number }[] = [
    { date: '06 Ags', omzet: 3_910_000, hpp: 2_795_000 },
    { date: '07 Ags', omzet: 4_240_000, hpp: 3_020_000 },
    { date: '08 Ags', omzet: 5_180_000, hpp: 3_690_000 },
    { date: '09 Ags', omzet: 5_640_000, hpp: 4_010_000 },
    { date: '10 Ags', omzet: 3_480_000, hpp: 2_490_000 },
    { date: '11 Ags', omzet: 3_920_000, hpp: 2_800_000 },
    { date: '12 Ags', omzet: 4_310_000, hpp: 3_070_000 },
    { date: '13 Ags', omzet: 4_690_000, hpp: 3_340_000 },
    { date: '14 Ags', omzet: 5_020_000, hpp: 3_570_000 },
    { date: '15 Ags', omzet: 5_880_000, hpp: 4_180_000 },
    { date: '16 Ags', omzet: 6_140_000, hpp: 4_360_000 },
    { date: '17 Ags', omzet: 4_070_000, hpp: 2_900_000 },
    { date: '18 Ags', omzet: 4_288_000, hpp: 3_108_400 },
    { date: '19 Ags', omzet: 4_820_500, hpp: 3_412_700 },
];

export const paymentMix: { type: PaymentType; label: string; amount: number }[] = [
    { type: 'CASH', label: 'Tunai', amount: 2_615_000 },
    { type: 'QRIS', label: 'QRIS', amount: 1_284_500 },
    { type: 'DEBIT', label: 'Debit', amount: 546_000 },
    { type: 'TRANSFER', label: 'Transfer', amount: 375_000 },
];

/* ---------------------------------------------------------------- produk */

export interface ProductUom {
    name: string;
    type: 'BASE' | 'PACK';
    factor: number;
    price: number;
}

export interface Product {
    id: number;
    sku: string;
    barcode: string;
    name: string;
    category: string;
    isWeighted: boolean;
    baseUom: string;
    uoms: ProductUom[];
    stock: number;
    minStock: number;
    avgCost: number;
    active: boolean;
    /** Foto produk. null = pakai gambar bawaan (lihat NO_IMAGE). */
    image?: string | null;
}

/** Gambar bawaan saat produk belum punya foto. */
export const NO_IMAGE = '/images/no-image.png';

export function productImage(p: Pick<Product, 'image'>): string {
    return p.image ?? NO_IMAGE;
}

export const products: Product[] = [
    {
        id: 1, sku: 'SKU-0001', barcode: '8992388101010', name: 'Indomie Goreng',
        category: 'Makanan Instan', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 3_500 },
            { name: 'dus', type: 'PACK', factor: 24, price: 84_000 },
            { name: 'karton', type: 'PACK', factor: 120, price: 410_000 },
        ],
        stock: 1_248, minStock: 240, avgCost: 2_500, active: true,
    },
    {
        id: 2, sku: 'SKU-0002', barcode: '8992388102027', name: 'Aqua Botol 600ml',
        category: 'Minuman', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 4_000 },
            { name: 'dus', type: 'PACK', factor: 24, price: 92_000 },
        ],
        stock: 86, minStock: 120, avgCost: 3_150, active: true,
    },
    {
        id: 3, sku: 'SKU-0003', barcode: '8992388103034', name: 'Beras Pandan Wangi',
        category: 'Sembako', isWeighted: true, baseUom: 'kg',
        uoms: [
            { name: 'kg', type: 'BASE', factor: 1, price: 14_500 },
            { name: 'karung', type: 'PACK', factor: 25, price: 352_000 },
        ],
        stock: 412.5, minStock: 100, avgCost: 12_200, active: true,
    },
    {
        id: 4, sku: 'SKU-0004', barcode: '8992388104041', name: 'Minyak Goreng Bimoli 1L',
        category: 'Sembako', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 18_500 },
            { name: 'dus', type: 'PACK', factor: 12, price: 216_000 },
        ],
        stock: 194, minStock: 60, avgCost: 16_100, active: true,
    },
    {
        id: 5, sku: 'SKU-0005', barcode: '8992388105058', name: 'Telur Ayam Negeri',
        category: 'Sembako', isWeighted: true, baseUom: 'kg',
        uoms: [{ name: 'kg', type: 'BASE', factor: 1, price: 29_000 }],
        stock: 38.25, minStock: 50, avgCost: 25_400, active: true,
    },
    {
        id: 6, sku: 'SKU-0006', barcode: '8992388106065', name: 'Gula Pasir Gulaku 1kg',
        category: 'Sembako', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 17_000 },
            { name: 'dus', type: 'PACK', factor: 20, price: 328_000 },
        ],
        stock: 156, minStock: 40, avgCost: 15_050, active: true,
    },
    {
        id: 7, sku: 'SKU-0007', barcode: '8992388107072', name: 'Kopi Kapal Api Sachet',
        category: 'Minuman', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 1_575 },
            { name: 'renceng', type: 'PACK', factor: 10, price: 14_000 },
            { name: 'dus', type: 'PACK', factor: 120, price: 162_000 },
        ],
        stock: 2_140, minStock: 480, avgCost: 1_080, active: true,
    },
    {
        id: 8, sku: 'SKU-0008', barcode: '8992388108089', name: 'Sabun Lifebuoy 85gr',
        category: 'Perawatan', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 4_250 },
            { name: 'lusin', type: 'PACK', factor: 12, price: 48_000 },
        ],
        stock: 312, minStock: 72, avgCost: 3_380, active: true,
    },
    {
        id: 9, sku: 'SKU-0009', barcode: '8992388109096', name: 'Susu Ultra Milk 250ml',
        category: 'Minuman', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 6_500 },
            { name: 'dus', type: 'PACK', factor: 24, price: 150_000 },
        ],
        stock: 44, minStock: 96, avgCost: 5_450, active: true,
    },
    {
        id: 10, sku: 'SKU-0010', barcode: '8992388110092', name: 'Rinso Cair 800ml',
        category: 'Perawatan', isWeighted: false, baseUom: 'pcs',
        uoms: [{ name: 'pcs', type: 'BASE', factor: 1, price: 22_500 }],
        stock: 78, minStock: 24, avgCost: 19_300, active: true,
    },
    {
        id: 11, sku: 'SKU-0011', barcode: '8992388111099', name: 'Teh Botol Sosro 350ml',
        category: 'Minuman', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 5_000 },
            { name: 'dus', type: 'PACK', factor: 24, price: 114_000 },
        ],
        stock: 268, minStock: 72, avgCost: 4_100, active: true,
    },
    {
        id: 12, sku: 'SKU-0012', barcode: '8992388112096', name: 'Tepung Terigu Segitiga 1kg',
        category: 'Sembako', isWeighted: false, baseUom: 'pcs',
        uoms: [
            { name: 'pcs', type: 'BASE', factor: 1, price: 13_000 },
            { name: 'dus', type: 'PACK', factor: 12, price: 150_000 },
        ],
        stock: 0, minStock: 24, avgCost: 11_400, active: false,
    },
];

export const categories = ['Sembako', 'Minuman', 'Makanan Instan', 'Perawatan'];

export const topProducts = [
    { name: 'Indomie Goreng', qty: 186, uom: 'pcs', omzet: 651_000 },
    { name: 'Aqua Botol 600ml', qty: 124, uom: 'pcs', omzet: 496_000 },
    { name: 'Kopi Kapal Api Sachet', qty: 310, uom: 'pcs', omzet: 465_000 },
    { name: 'Beras Pandan Wangi', qty: 28.5, uom: 'kg', omzet: 413_250 },
    { name: 'Minyak Goreng Bimoli 1L', qty: 19, uom: 'pcs', omzet: 351_500 },
];

/* ------------------------------------------------------------- penjualan */

export interface SaleItem {
    product: string;
    qty: number;
    uom: string;
    price: number;
    cost: number;
}

export interface Sale {
    id: number;
    number: string;
    date: string;
    time: string;
    customer: string | null;
    cashier: string;
    status: DocStatus;
    payment: PaymentType;
    itemCount: number;
    grossTotal: number;
    discountTotal: number;
    taxTotal: number;
    netTotal: number;
    roundingAmount: number;
    paidTotal: number;
    cogs: number;
    items: SaleItem[];
}

export const sales: Sale[] = [
    {
        id: 1, number: 'INV/BR01/2026-08/000137', date: '2026-08-19', time: '14:22',
        customer: null, cashier: 'Rina', status: 'POSTED', payment: 'CASH',
        itemCount: 3, grossTotal: 7_560, discountTotal: 0, taxTotal: 0,
        netTotal: 7_560, roundingAmount: -60, paidTotal: 7_500, cogs: 5_240,
        items: [
            { product: 'Indomie Goreng', qty: 1, uom: 'pcs', price: 3_500, cost: 2_500 },
            { product: 'Kopi Kapal Api Sachet', qty: 1, uom: 'pcs', price: 1_500, cost: 1_080 },
            { product: 'Teh Botol Sosro 350ml', qty: 1, uom: 'pcs', price: 2_560, cost: 1_660 },
        ],
    },
    {
        id: 2, number: 'INV/BR01/2026-08/000136', date: '2026-08-19', time: '14:05',
        customer: 'Warung Bu Sri', cashier: 'Rina', status: 'POSTED', payment: 'QRIS',
        itemCount: 5, grossTotal: 428_000, discountTotal: 12_000, taxTotal: 0,
        netTotal: 416_000, roundingAmount: 0, paidTotal: 416_000, cogs: 302_400,
        items: [
            { product: 'Indomie Goreng', qty: 2, uom: 'dus', price: 84_000, cost: 60_000 },
            { product: 'Aqua Botol 600ml', qty: 1, uom: 'dus', price: 92_000, cost: 75_600 },
            { product: 'Gula Pasir Gulaku 1kg', qty: 4, uom: 'pcs', price: 17_000, cost: 15_050 },
            { product: 'Minyak Goreng Bimoli 1L', qty: 4, uom: 'pcs', price: 18_500, cost: 16_100 },
            { product: 'Sabun Lifebuoy 85gr', qty: 5, uom: 'pcs', price: 4_200, cost: 3_380 },
        ],
    },
    {
        id: 3, number: 'INV/BR01/2026-08/000135', date: '2026-08-19', time: '13:41',
        customer: null, cashier: 'Dedi', status: 'POSTED', payment: 'CASH',
        itemCount: 2, grossTotal: 43_500, discountTotal: 0, taxTotal: 0,
        netTotal: 43_500, roundingAmount: 0, paidTotal: 43_500, cogs: 36_600,
        items: [
            { product: 'Beras Pandan Wangi', qty: 2, uom: 'kg', price: 14_500, cost: 12_200 },
            { product: 'Telur Ayam Negeri', qty: 0.5, uom: 'kg', price: 14_500, cost: 12_200 },
        ],
    },
    {
        id: 4, number: 'INV/BR01/2026-08/000134', date: '2026-08-19', time: '12:58',
        customer: 'Toko Berkah', cashier: 'Dedi', status: 'VOID', payment: 'CASH',
        itemCount: 1, grossTotal: 162_000, discountTotal: 0, taxTotal: 0,
        netTotal: 162_000, roundingAmount: 0, paidTotal: 162_000, cogs: 129_600,
        items: [{ product: 'Kopi Kapal Api Sachet', qty: 1, uom: 'dus', price: 162_000, cost: 129_600 }],
    },
    {
        id: 5, number: 'INV/BR01/2026-08/000133', date: '2026-08-19', time: '12:30',
        customer: null, cashier: 'Rina', status: 'POSTED', payment: 'E_WALLET',
        itemCount: 4, grossTotal: 96_400, discountTotal: 0, taxTotal: 0,
        netTotal: 96_400, roundingAmount: -400, paidTotal: 96_000, cogs: 78_900,
        items: [
            { product: 'Susu Ultra Milk 250ml', qty: 4, uom: 'pcs', price: 6_500, cost: 5_450 },
            { product: 'Rinso Cair 800ml', qty: 2, uom: 'pcs', price: 22_500, cost: 19_300 },
            { product: 'Teh Botol Sosro 350ml', qty: 3, uom: 'pcs', price: 5_000, cost: 4_100 },
            { product: 'Sabun Lifebuoy 85gr', qty: 3, uom: 'pcs', price: 4_200, cost: 3_380 },
        ],
    },
    {
        id: 6, number: 'INV/BR01/2026-08/000132', date: '2026-08-19', time: '11:47',
        customer: null, cashier: 'Rina', status: 'POSTED', payment: 'DEBIT',
        itemCount: 2, grossTotal: 352_000, discountTotal: 0, taxTotal: 0,
        netTotal: 352_000, roundingAmount: 0, paidTotal: 352_000, cogs: 305_000,
        items: [{ product: 'Beras Pandan Wangi', qty: 1, uom: 'karung', price: 352_000, cost: 305_000 }],
    },
    {
        id: 7, number: 'INV/BR01/2026-08/000131', date: '2026-08-19', time: '10:12',
        customer: null, cashier: 'Dedi', status: 'POSTED', payment: 'CASH',
        itemCount: 6, grossTotal: 128_700, discountTotal: 5_000, taxTotal: 0,
        netTotal: 123_700, roundingAmount: -200, paidTotal: 123_500, cogs: 99_800,
        items: [
            { product: 'Indomie Goreng', qty: 10, uom: 'pcs', price: 3_500, cost: 2_500 },
            { product: 'Aqua Botol 600ml', qty: 6, uom: 'pcs', price: 4_000, cost: 3_150 },
            { product: 'Gula Pasir Gulaku 1kg', qty: 2, uom: 'pcs', price: 17_000, cost: 15_050 },
            { product: 'Kopi Kapal Api Sachet', qty: 10, uom: 'pcs', price: 1_500, cost: 1_080 },
            { product: 'Sabun Lifebuoy 85gr', qty: 2, uom: 'pcs', price: 4_200, cost: 3_380 },
            { product: 'Teh Botol Sosro 350ml', qty: 2, uom: 'pcs', price: 5_000, cost: 4_100 },
        ],
    },
    {
        id: 8, number: 'INV/BR01/2026-08/000130', date: '2026-08-18', time: '19:34',
        customer: 'Warung Bu Sri', cashier: 'Rina', status: 'POSTED', payment: 'TRANSFER',
        itemCount: 3, grossTotal: 375_000, discountTotal: 0, taxTotal: 0,
        netTotal: 375_000, roundingAmount: 0, paidTotal: 375_000, cogs: 298_500,
        items: [
            { product: 'Minyak Goreng Bimoli 1L', qty: 1, uom: 'dus', price: 216_000, cost: 193_200 },
            { product: 'Tepung Terigu Segitiga 1kg', qty: 6, uom: 'pcs', price: 13_000, cost: 11_400 },
            { product: 'Gula Pasir Gulaku 1kg', qty: 5, uom: 'pcs', price: 17_000, cost: 15_050 },
        ],
    },
];

/* ------------------------------------------------------------- pembelian */

export interface Purchase {
    id: number;
    number: string;
    date: string;
    supplier: string;
    status: DocStatus;
    itemCount: number;
    total: number;
    paid: number;
}

export const purchases: Purchase[] = [
    { id: 1, number: 'PUR/BR01/2026-08/000024', date: '2026-08-18', supplier: 'CV Sumber Rejeki', status: 'POSTED', itemCount: 8, total: 4_260_000, paid: 4_260_000 },
    { id: 2, number: 'PUR/BR01/2026-08/000023', date: '2026-08-16', supplier: 'PT Anugerah Pangan', status: 'POSTED', itemCount: 5, total: 2_880_000, paid: 1_500_000 },
    { id: 3, number: 'PUR/BR01/2026-08/000022', date: '2026-08-14', supplier: 'CV Sumber Rejeki', status: 'POSTED', itemCount: 12, total: 6_140_000, paid: 6_140_000 },
    { id: 4, number: 'PUR/BR01/2026-08/000021', date: '2026-08-11', supplier: 'UD Maju Jaya', status: 'POSTED', itemCount: 3, total: 1_275_000, paid: 0 },
    { id: 5, number: 'PUR/BR01/2026-08/000020', date: '2026-08-08', supplier: 'PT Anugerah Pangan', status: 'DRAFT', itemCount: 6, total: 3_410_000, paid: 0 },
];

/* ------------------------------------------------------------------ stok */

export interface StockMovement {
    id: number;
    date: string;
    type: 'OPENING' | 'PURCHASE' | 'SALES' | 'ADJUSTMENT_IN' | 'ADJUSTMENT_OUT' | 'TRANSFER_IN' | 'TRANSFER_OUT' | 'RETURN_IN' | 'VOID_REVERSAL';
    reference: string;
    product: string;
    qty: number;
    uom: string;
    unitCost: number;
    balance: number;
}

export const stockMovements: StockMovement[] = [
    { id: 1, date: '2026-08-19', type: 'SALES', reference: 'INV/BR01/2026-08/000137', product: 'Indomie Goreng', qty: -1, uom: 'pcs', unitCost: 2_500, balance: 1_248 },
    { id: 2, date: '2026-08-19', type: 'SALES', reference: 'INV/BR01/2026-08/000136', product: 'Indomie Goreng', qty: -48, uom: 'pcs', unitCost: 2_500, balance: 1_249 },
    { id: 3, date: '2026-08-19', type: 'VOID_REVERSAL', reference: 'INV/BR01/2026-08/000134', product: 'Kopi Kapal Api Sachet', qty: 120, uom: 'pcs', unitCost: 1_080, balance: 2_140 },
    { id: 4, date: '2026-08-18', type: 'PURCHASE', reference: 'PUR/BR01/2026-08/000024', product: 'Indomie Goreng', qty: 480, uom: 'pcs', unitCost: 2_500, balance: 1_297 },
    { id: 5, date: '2026-08-18', type: 'ADJUSTMENT_OUT', reference: 'ADJ/BR01/2026-08/000003', product: 'Telur Ayam Negeri', qty: -1.75, uom: 'kg', unitCost: 25_400, balance: 38.25 },
    { id: 6, date: '2026-08-17', type: 'SALES', reference: 'INV/BR01/2026-08/000128', product: 'Aqua Botol 600ml', qty: -24, uom: 'pcs', unitCost: 3_150, balance: 86 },
    { id: 7, date: '2026-08-16', type: 'PURCHASE', reference: 'PUR/BR01/2026-08/000023', product: 'Beras Pandan Wangi', qty: 250, uom: 'kg', unitCost: 12_200, balance: 412.5 },
    { id: 8, date: '2026-08-01', type: 'OPENING', reference: 'FP-2026-08', product: 'Indomie Goreng', qty: 865, uom: 'pcs', unitCost: 2_480, balance: 865 },
];

/* --------------------------------------------------------- kas dan biaya */

export const cashSummary = {
    opening: 1_250_000,
    cashIn: 2_615_000,
    cashOut: 486_000,
    refund: 0,
    closing: 3_379_000,
    difference: -15_000,
};

export interface Expense {
    id: number;
    number: string;
    date: string;
    category: string;
    description: string;
    amount: number;
    payment: PaymentType;
    status: DocStatus;
}

export const expenses: Expense[] = [
    { id: 1, number: 'EXP/BR01/2026-08/000018', date: '2026-08-19', category: 'Transport', description: 'Bensin antar pesanan', amount: 85_000, payment: 'CASH', status: 'POSTED' },
    { id: 2, number: 'EXP/BR01/2026-08/000017', date: '2026-08-18', category: 'Perlengkapan', description: 'Kantong plastik 5 pak', amount: 126_000, payment: 'CASH', status: 'POSTED' },
    { id: 3, number: 'EXP/BR01/2026-08/000016', date: '2026-08-15', category: 'Listrik', description: 'Token listrik toko', amount: 275_000, payment: 'QRIS', status: 'POSTED' },
    { id: 4, number: 'EXP/BR01/2026-08/000015', date: '2026-08-10', category: 'Gaji', description: 'Gaji kasir periode 1', amount: 2_400_000, payment: 'TRANSFER', status: 'POSTED' },
    { id: 5, number: 'EXP/BR01/2026-08/000014', date: '2026-08-05', category: 'Sewa', description: 'Sewa ruko Agustus', amount: 3_500_000, payment: 'TRANSFER', status: 'POSTED' },
];

export const expenseByCategory = [
    { category: 'Sewa', amount: 3_500_000 },
    { category: 'Gaji', amount: 2_400_000 },
    { category: 'Listrik', amount: 275_000 },
    { category: 'Perlengkapan', amount: 126_000 },
    { category: 'Transport', amount: 85_000 },
];

/* --------------------------------------------------------- fiscal period */

export interface FiscalPeriod {
    id: number;
    code: string;
    name: string;
    startDate: string;
    endDate: string;
    status: PeriodStatus;
    costingMethod: 'AVERAGE' | 'FIFO';
    transactions: number;
    netSales: number;
    cogs: number;
    grossProfit: number;
    expenseTotal: number;
    netProfit: number;
    closedAt: string | null;
    closedBy: string | null;
}

export const fiscalPeriods: FiscalPeriod[] = [
    {
        id: 3, code: 'FP-2026-08', name: 'Agustus 2026', startDate: '2026-08-01', endDate: '2026-08-31',
        status: 'OPEN', costingMethod: 'AVERAGE', transactions: 1_284,
        netSales: 68_420_000, cogs: 48_910_000, grossProfit: 19_510_000,
        expenseTotal: 6_386_000, netProfit: 13_124_000, closedAt: null, closedBy: null,
    },
    {
        id: 2, code: 'FP-2026-07', name: 'Juli 2026', startDate: '2026-07-01', endDate: '2026-07-31',
        status: 'CLOSED', costingMethod: 'AVERAGE', transactions: 2_106,
        netSales: 112_380_000, cogs: 80_140_000, grossProfit: 32_240_000,
        expenseTotal: 9_820_000, netProfit: 22_420_000, closedAt: '2026-08-02 09:14', closedBy: 'Bagus (Owner)',
    },
    {
        id: 1, code: 'FP-2026-06', name: 'Juni 2026', startDate: '2026-06-01', endDate: '2026-06-30',
        status: 'CLOSED', costingMethod: 'AVERAGE', transactions: 1_948,
        netSales: 104_610_000, cogs: 74_890_000, grossProfit: 29_720_000,
        expenseTotal: 9_140_000, netProfit: 20_580_000, closedAt: '2026-07-01 20:40', closedBy: 'Bagus (Owner)',
    },
];

/** Hasil pemeriksaan sebelum periode boleh ditutup (Addendum §55.3, §46, §43). */
export const preClosingChecks = [
    { label: 'Semua shift kasir sudah ditutup', status: 'ok' as const, detail: '6 shift, seluruhnya CLOSED' },
    { label: 'Tidak ada transaksi berstatus DRAFT', status: 'warn' as const, detail: '1 pembelian masih DRAFT' },
    { label: 'Tidak ada produk bersaldo negatif', status: 'ok' as const, detail: 'Tidak ditemukan' },
    { label: 'Selisih kas sudah diselesaikan', status: 'error' as const, detail: 'Rp15.000 belum diselesaikan' },
    { label: 'Stock opname periode ini', status: 'ok' as const, detail: 'Selesai 18 Ags 2026' },
];

/* --------------------------------------------------------------- aktivitas */

export const recentActivity = [
    { time: '14:22', user: 'Rina', action: 'Menyimpan penjualan', target: 'INV/BR01/2026-08/000137' },
    { time: '13:02', user: 'Bagus', action: 'Membatalkan penjualan', target: 'INV/BR01/2026-08/000134' },
    { time: '11:15', user: 'Dedi', action: 'Menambah biaya', target: 'EXP/BR01/2026-08/000018' },
    { time: '09:48', user: 'Bagus', action: 'Mengubah harga jual', target: 'Susu Ultra Milk 250ml' },
    { time: '08:30', user: 'Rina', action: 'Membuka shift', target: 'Shift pagi — kas awal Rp1.250.000' },
];
