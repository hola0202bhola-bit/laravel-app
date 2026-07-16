<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $salesTotal = Sale::query()->pluck('total')->reduce(
            fn (string $total, $amount) => bcadd($total, (string) $amount, 2),
            '0.00'
        );

        return response()->json([
            'orders_count' => Order::count(),
            'sales_total' => $salesTotal,
            'low_stock' => Product::with('category')
                ->where('existencia', '<=', 5)
                ->orderBy('existencia')
                ->get(),
            'recent_orders' => Order::latest()->limit(10)->get(),
            'recent_sales' => Sale::latest()->limit(10)->get(),
        ]);
    }
}
