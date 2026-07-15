<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ItemStateTransitionService
{
    public const ALLOWED_TRANSITIONS = [
        'pendiente' => ['en_preparacion', 'cancelado'],
        'en_preparacion' => ['listo', 'cancelado'],
        'listo' => [],
        'cancelado' => [],
    ];

    public const MANAGER_TRANSITIONS = [
        'listo' => ['en_preparacion'],
    ];

    public static function isAllowed(string $from, string $to, bool $isManager = false): bool
    {
        if (in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [])) {
            return true;
        }

        if ($isManager && in_array($to, self::MANAGER_TRANSITIONS[$from] ?? [])) {
            return true;
        }

        return false;
    }

    public static function validate(string $from, string $to, bool $isManager = false): void
    {
        if (!self::isAllowed($from, $to, $isManager)) {
            throw new HttpException(
                422,
                "Transición de estado inválida de '{$from}' a '{$to}'."
            );
        }
    }

    public static function recalculatePreparationStatus(array $items): string
    {
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
