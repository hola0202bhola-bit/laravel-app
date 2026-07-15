<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Order;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Go through all existing orders and update their items JSON to include unique ids and status
        Order::all()->each(function (Order $order) {
            $items = $order->items;
            if (is_array($items)) {
                $updated = false;
                $newItems = array_map(function ($item) use (&$updated) {
                    if (!isset($item['id'])) {
                        $item['id'] = 'item_' . Str::random(10);
                        $updated = true;
                    }
                    if (!isset($item['estado'])) {
                        $item['estado'] = 'pendiente';
                        $updated = true;
                    }
                    return $item;
                }, $items);

                if ($updated) {
                    $order->update(['items' => $newItems]);
                }
            }
        });
    }

    public function down(): void
    {
        // Revert by removing id and status keys from the items JSON
        Order::all()->each(function (Order $order) {
            $items = $order->items;
            if (is_array($items)) {
                $newItems = array_map(function ($item) {
                    unset($item['id']);
                    unset($item['estado']);
                    return $item;
                }, $items);
                $order->update(['items' => $newItems]);
            }
        });
    }
};
