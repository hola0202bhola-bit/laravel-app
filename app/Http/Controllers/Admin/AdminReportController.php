<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminReportController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    public function __construct(private ReportService $reports)
    {
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->range($request);

        return response()->json($this->reports->report($start, $end));
    }

    public function exportSales(Request $request)
    {
        [$start, $end] = $this->range($request);

        return $this->csvResponse(
            "ventas_{$start->toDateString()}_{$end->toDateString()}.csv",
            ['ID venta', 'Fecha', 'Total', 'Productos'],
            $this->reports->salesRows($start, $end)
        );
    }

    public function exportOrders(Request $request)
    {
        [$start, $end] = $this->range($request);

        return $this->csvResponse(
            "pedidos_{$start->toDateString()}_{$end->toDateString()}.csv",
            ['ID pedido', 'Fecha', 'Estado', 'Tipo de pedido', 'Mesa', 'Método de pago', 'Total', 'Productos'],
            $this->reports->orderRows($start, $end)
        );
    }

    private function range(Request $request): array
    {
        $data = $request->validate([
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $end = isset($data['fecha_fin'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['fecha_fin'])->startOfDay()
            : CarbonImmutable::today();
        $start = isset($data['fecha_inicio'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['fecha_inicio'])->startOfDay()
            : $end->subDays(29);

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            ]);
        }

        if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'El rango no puede exceder 366 días.',
            ]);
        }

        return [$start, $end];
    }

    private function csvResponse(string $filename, array $headers, array $rows)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map([$this, 'safeCsvValue'], $headers));
        foreach ($rows as $row) {
            fputcsv($stream, array_map([$this, 'safeCsvValue'], $row));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'{$value}";
        }

        return $value;
    }
}
