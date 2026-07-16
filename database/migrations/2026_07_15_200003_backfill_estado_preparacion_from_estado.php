<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Chunk orders by ID to process safely without loading all in memory
        DB::table('orders')->orderBy('id')->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                $items = json_decode($order->items, true);
                $estadoPreparacion = 'pendiente';
                $trackingToken = Str::uuid()->toString();

                if (is_array($items) && count($items) > 0) {
                    // Check if all items are still in 'pendiente' (unoperated kitchen status)
                    $allPendiente = true;
                    foreach ($items as $item) {
                        $itemEstado = $item['estado'] ?? 'pendiente';
                        if ($itemEstado !== 'pendiente') {
                            $allPendiente = false;
                            break;
                        }
                    }

                    if ($allPendiente) {
                        // Map items status derived safely from the commercial state of the order
                        $targetStatus = match ($order->estado) {
                            'listo', 'entregado' => 'listo',
                            'en_preparacion' => 'en_preparacion',
                            'cancelado', 'rechazado', 'reembolsado' => 'cancelado',
                            default => 'pendiente',
                        };

                        foreach ($items as &$item) {
                            $item['estado'] = $targetStatus;
                            if (!isset($item['id'])) {
                                $item['id'] = 'item_' . Str::random(10);
                            }
                        }
                        $estadoPreparacion = $targetStatus;
                    } else {
                        // There was already operated kitchen activity (individual item states differ)
                        // Do not overwrite item statuses, instead calculate estado_preparacion from them
                        $allCancelled = true;
                        $anyPrep = false;
                        $anyPending = false;
                        $anyReady = false;

                        foreach ($items as $item) {
                            $itemEstado = $item['estado'] ?? 'pendiente';
                            if ($itemEstado !== 'cancelado') {
                                $allCancelled = false;
                            }
                            if ($itemEstado === 'en_preparacion') {
                                $anyPrep = true;
                            }
                            if ($itemEstado === 'pendiente') {
                                $anyPending = true;
                            }
                            if ($itemEstado === 'listo') {
                                $anyReady = true;
                            }
                        }

                        if ($allCancelled) {
                            $estadoPreparacion = 'cancelado';
                        } elseif ($anyPrep || ($anyReady && $anyPending)) {
                            $estadoPreparacion = 'en_preparacion';
                        } elseif ($anyReady && !$anyPending && !$anyPrep) {
                            $estadoPreparacion = 'listo';
                        } else {
                            $estadoPreparacion = 'pendiente';
                        }
                    }
                }

                // Update directly via query builder to bypass Eloquent events
                DB::table('orders')->where('id', $order->id)->update([
                    'items' => json_encode($items),
                    'estado_preparacion' => $estadoPreparacion,
                    'tracking_token' => $trackingToken,
                    'lock_version' => 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        // ADVERTENCIA: Este down() NO restaura los estados individuales de ítems
        // que hayan sido modificados por la cocina tras ejecutar up().
        // Los campos estado_preparacion y tracking_token se eliminan en la
        // migración de esquema correspondiente (add_estado_preparacion_to_orders).
        // Este método se deja intencionalmente vacío porque no existe reversión
        // sin pérdida de información operativa de cocina.
        //
        // Para revertir de forma segura, restaurar desde un respaldo físico.
    }
};
