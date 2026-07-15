<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use App\Services\ItemStateTransitionService;

class KitchenController extends Controller
{
    public function index()
    {
        // Query based on preparation status instead of commercial status
        $activeOrders = Order::whereIn('estado_preparacion', ['pendiente', 'en_preparacion', 'listo'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($activeOrders);
    }

    public function updateStatus(Request $request)
    {
        // Global KDS action only allows en_preparacion or listo
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'estado' => 'required|string|in:en_preparacion,listo'
        ]);

        $maxRetries = 3;
        $attempt = 0;
        $saved = false;
        $order = null;

        do {
            $attempt++;
            DB::beginTransaction();

            $order = Order::findOrFail($validated['order_id']);
            $items = $order->items;
            $auditsToInsert = [];
            $targetStatus = $validated['estado'];
            $estadoAnteriorGlobal = $order->estado_preparacion;

            if (is_array($items)) {
                foreach ($items as &$item) {
                    $oldStatus = $item['estado'] ?? 'pendiente';

                    if ($targetStatus === 'en_preparacion') {
                        // "Iniciar Todo" only changes pending items, doesn't touch ready/cancelled
                        if ($oldStatus === 'pendiente') {
                            $item['estado'] = 'en_preparacion';
                            $auditsToInsert[] = [
                                'order_id' => $order->id,
                                'user_id' => $request->user() ? $request->user()->id : null,
                                'item_id' => $item['id'],
                                'estado_anterior' => $oldStatus,
                                'estado_nuevo' => 'en_preparacion',
                                'motivo' => null,
                                'cambiado_en' => now(),
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                    } elseif ($targetStatus === 'listo') {
                        // "Terminar Todo" changes pending and prep items to listo, doesn't touch cancelled
                        if ($oldStatus === 'pendiente' || $oldStatus === 'en_preparacion') {
                            $item['estado'] = 'listo';
                            $auditsToInsert[] = [
                                'order_id' => $order->id,
                                'user_id' => $request->user() ? $request->user()->id : null,
                                'item_id' => $item['id'],
                                'estado_anterior' => $oldStatus,
                                'estado_nuevo' => 'listo',
                                'motivo' => null,
                                'cambiado_en' => now(),
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                    }
                }
            }

            // Recalculate preparation status from items
            $newEstadoPreparacion = ItemStateTransitionService::recalculatePreparationStatus($items);

            // Conditional update based on lock_version
            $affected = DB::table('orders')
                ->where('id', $order->id)
                ->where('lock_version', $order->lock_version)
                ->update([
                    'items' => json_encode($items),
                    'estado_preparacion' => $newEstadoPreparacion,
                    'lock_version' => $order->lock_version + 1,
                    'updated_at' => now()
                ]);

            if ($affected > 0) {
                // Insert granular audits
                if (!empty($auditsToInsert)) {
                    DB::table('order_status_histories')->insert($auditsToInsert);
                }

                // Log global status history change
                DB::table('order_status_histories')->insert([
                    'order_id' => $order->id,
                    'user_id' => $request->user() ? $request->user()->id : null,
                    'item_id' => null,
                    'estado_anterior' => $estadoAnteriorGlobal,
                    'estado_nuevo' => $newEstadoPreparacion,
                    'motivo' => null,
                    'cambiado_en' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                DB::commit();
                $saved = true;
                $order->estado_preparacion = $newEstadoPreparacion;
                $order->items = $items;
                $order->lock_version += 1;
                break;
            }

            DB::rollBack();
            if ($attempt < $maxRetries) {
                usleep(50000 * $attempt);
            }
        } while ($attempt < $maxRetries);

        if (!$saved) {
            return response()->json(['error' => 'Conflicto de escritura concurrente. Por favor reintente.'], 409);
        }

        return response()->json([
            'message' => "Éxito: Orden #{$order->id} movida a '{$validated['estado']}'.",
            'order' => $order
        ]);
    }

    public function updateItemStatus(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'item_id' => 'required|string',
            'estado' => 'required|string|in:pendiente,en_preparacion,listo,cancelado',
            'motivo' => 'nullable|string|min:5'
        ]);

        $maxRetries = 3;
        $attempt = 0;
        $saved = false;
        $order = null;

        do {
            $attempt++;
            DB::beginTransaction();

            $order = Order::findOrFail($validated['order_id']);
            $items = $order->items;
            $updated = false;
            $estadoAnterior = null;

            if (is_array($items)) {
                foreach ($items as &$item) {
                    if (isset($item['id']) && $item['id'] === $validated['item_id']) {
                        $estadoAnterior = $item['estado'] ?? 'pendiente';

                        // Check authorization and manager role
                        $user = $request->user();
                        $isManager = $user && ($user->hasRole('Administrador') || $user->hasRole('Gerente'));

                        // Verify manager reason for listo -> en_preparacion
                        if ($estadoAnterior === 'listo' && $validated['estado'] === 'en_preparacion') {
                            if (empty($validated['motivo'])) {
                                DB::rollBack();
                                return response()->json(['error' => 'El motivo es obligatorio para revertir un producto listo.'], 422);
                            }
                        }

                        // Validate transition using central transition service rules
                        ItemStateTransitionService::validate($estadoAnterior, $validated['estado'], $isManager);

                        $item['estado'] = $validated['estado'];
                        $updated = true;
                        break;
                    }
                }
            }

            if (!$updated) {
                DB::rollBack();
                return response()->json(['error' => 'Error: Producto no encontrado en el pedido.'], 404);
            }

            // Recalculate preparation status from items
            $newEstadoPreparacion = ItemStateTransitionService::recalculatePreparationStatus($items);

            // Conditional update based on lock_version
            $affected = DB::table('orders')
                ->where('id', $order->id)
                ->where('lock_version', $order->lock_version)
                ->update([
                    'items' => json_encode($items),
                    'estado_preparacion' => $newEstadoPreparacion,
                    'lock_version' => $order->lock_version + 1,
                    'updated_at' => now()
                ]);

            if ($affected > 0) {
                // Save granular audit trail
                DB::table('order_status_histories')->insert([
                    'order_id' => $order->id,
                    'user_id' => $request->user() ? $request->user()->id : null,
                    'item_id' => $validated['item_id'],
                    'estado_anterior' => $estadoAnterior,
                    'estado_nuevo' => $validated['estado'],
                    'motivo' => $validated['motivo'] ?? null,
                    'cambiado_en' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                DB::commit();
                $saved = true;
                $order->estado_preparacion = $newEstadoPreparacion;
                $order->items = $items;
                $order->lock_version += 1;
                break;
            }

            DB::rollBack();
            if ($attempt < $maxRetries) {
                usleep(50000 * $attempt);
            }
        } while ($attempt < $maxRetries);

        if (!$saved) {
            return response()->json(['error' => 'Conflicto de escritura concurrente. Por favor reintente.'], 409);
        }

        return response()->json([
            'message' => "Éxito: Estado del producto actualizado a '{$validated['estado']}'.",
            'order' => $order
        ]);
    }
}
