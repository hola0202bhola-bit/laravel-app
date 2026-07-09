<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Sale;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function stats()
    {
        $sales = Sale::all();

        // 1. Sales Trend (by Order ID / Date)
        $salesTrend = $sales->take(10)->map(function ($s) {
            return [
                'label' => '#' . $s->id,
                'total' => floatval($s->total)
            ];
        });

        // 2. Top Products Popularity
        $productCounts = [];
        foreach ($sales as $sale) {
            $items = $sale->items ?? [];
            foreach ($items as $item) {
                $nombre = $item['nombre'] ?? 'Producto';
                $productCounts[$nombre] = ($productCounts[$nombre] ?? 0) + intval($item['cantidad'] ?? 1);
            }
        }

        arsort($productCounts);
        $topProducts = array_slice($productCounts, 0, 5, true);

        // 3. Payment Methods Breakdown
        $orders = Order::all();
        $paymentBreakdown = [
            'Efectivo' => $orders->where('metodo_pago', 'efectivo')->sum('total'),
            'Tarjeta' => $orders->where('metodo_pago', 'tarjeta')->sum('total'),
            'Delivery App' => $orders->where('metodo_pago', 'delivery')->sum('total')
        ];

        return response()->json([
            'salesTrend' => $salesTrend,
            'topProducts' => [
                'labels' => array_keys($topProducts),
                'data' => array_values($topProducts)
            ],
            'paymentBreakdown' => $paymentBreakdown,
            'grandTotal' => $sales->sum('total'),
            'totalOrders' => $orders->count()
        ]);
    }
}
