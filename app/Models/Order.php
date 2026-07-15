<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'total' => 'float'
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Order $order) {
            // Recalculate status based on individual items
            $order->estado = $order->recalculateStatus();
        });
    }

    public function recalculateStatus(): string
    {
        $items = $this->items ?? [];
        if (empty($items)) {
            return 'pendiente';
        }

        $allCancelled = true;
        $anyPrep = false;
        $anyPending = false;
        $anyReady = false;

        foreach ($items as $item) {
            $estado = $item['estado'] ?? 'pendiente';
            if ($estado !== 'cancelado') {
                $allCancelled = false;
            }
            if ($estado === 'en_preparacion') {
                $anyPrep = true;
            }
            if ($estado === 'pendiente') {
                $anyPending = true;
            }
            if ($estado === 'listo') {
                $anyReady = true;
            }
        }

        if ($allCancelled) {
            return 'cancelado';
        }

        // If at least one non-cancelled item is in preparation, or if we have a mix of ready and pending
        if ($anyPrep || ($anyReady && $anyPending)) {
            return 'en_preparacion';
        }

        // If all non-cancelled items are ready
        if ($anyReady && !$anyPending && !$anyPrep) {
            return 'listo';
        }

        // Default to pending if all non-cancelled items are pending
        return 'pendiente';
    }
}
