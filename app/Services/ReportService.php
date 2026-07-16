<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReportService
{
    public function report(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sales = $this->sales($start, $end);
        $orders = $this->orders($start, $end);
        $totalSales = $this->sumMoney($sales->pluck('total'));
        $orderCount = $orders->count();

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'order_count' => $orderCount,
                'average_ticket' => $this->averageMoney($totalSales, $orderCount),
            ],
            'daily_sales' => $this->dailySales($sales),
            'top_products' => $this->topProducts($sales),
            'orders_by_status' => $this->ordersByStatus($orders),
            'low_inventory' => Product::query()
                ->where('existencia', '<=', 5)
                ->orderBy('existencia')
                ->orderBy('nombre')
                ->get(['codigo', 'nombre', 'existencia'])
                ->map(fn (Product $product) => [
                    'codigo' => $product->codigo,
                    'nombre' => $product->nombre,
                    'existencia' => $product->existencia,
                ])->values()->all(),
        ];
    }

    public function salesRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->sales($start, $end)->map(fn (Sale $sale) => [
            $sale->id,
            $sale->created_at->format('Y-m-d H:i:s'),
            (string) $sale->total,
            $this->itemsSummary($sale->items ?? []),
        ])->all();
    }

    public function orderRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->orders($start, $end)->map(fn (Order $order) => [
            $order->id,
            $order->created_at->format('Y-m-d H:i:s'),
            $order->estado,
            $order->tipo_pedido,
            $order->numero_mesa,
            $order->metodo_pago,
            (string) $order->total,
            $this->itemsSummary($order->items ?? []),
        ])->all();
    }

    private function sales(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Sale::query()
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function orders(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Order::query()
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function dailySales(Collection $sales): array
    {
        $daily = [];
        foreach ($sales as $sale) {
            $date = $sale->created_at->toDateString();
            $daily[$date] = bcadd($daily[$date] ?? '0.00', (string) $sale->total, 2);
        }
        ksort($daily);

        return collect($daily)->map(fn (string $total, string $date) => [
            'date' => $date,
            'total' => $total,
        ])->values()->all();
    }

    private function topProducts(Collection $sales): array
    {
        $products = [];
        foreach ($sales as $sale) {
            foreach ($sale->items ?? [] as $item) {
                $name = (string) ($item['nombre'] ?? 'Producto');
                $code = $item['codigo'] ?? null;
                $key = $code === null ? "name:{$name}" : "code:{$code}";
                $quantity = max(0, (int) ($item['cantidad'] ?? 1));
                $subtotal = isset($item['subtotal'])
                    ? bcadd((string) $item['subtotal'], '0.00', 2)
                    : bcmul((string) ($item['precioFinalUnitario'] ?? '0.00'), (string) $quantity, 2);

                if (!isset($products[$key])) {
                    $products[$key] = [
                        'codigo' => $code,
                        'nombre' => $name,
                        'cantidad' => 0,
                        'total' => '0.00',
                    ];
                }
                $products[$key]['cantidad'] += $quantity;
                $products[$key]['total'] = bcadd($products[$key]['total'], $subtotal, 2);
            }
        }

        $products = array_values($products);
        usort($products, fn (array $left, array $right) =>
            ($right['cantidad'] <=> $left['cantidad']) ?: strcmp($left['nombre'], $right['nombre'])
        );

        return array_slice($products, 0, 10);
    }

    private function ordersByStatus(Collection $orders): array
    {
        $statuses = [];
        foreach ($orders as $order) {
            $statuses[$order->estado] = ($statuses[$order->estado] ?? 0) + 1;
        }
        ksort($statuses);

        return collect($statuses)->map(fn (int $count, string $status) => [
            'status' => $status,
            'count' => $count,
        ])->values()->all();
    }

    private function averageMoney(string $total, int $count): string
    {
        if ($count === 0) {
            return '0.00';
        }

        $threeDecimals = bcdiv($total, (string) $count, 3);

        return bcadd($threeDecimals, '0.005', 2);
    }

    private function sumMoney(iterable $amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    private function itemsSummary(array $items): string
    {
        return collect($items)->map(fn (array $item) => sprintf(
            '%s x%d',
            (string) ($item['nombre'] ?? 'Producto'),
            (int) ($item['cantidad'] ?? 1)
        ))->implode(' | ');
    }
}
