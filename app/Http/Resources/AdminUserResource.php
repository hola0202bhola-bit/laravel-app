<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->whenLoaded('roles', fn () => $this->roles->first());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'role' => $role ? [
                'id' => $role->id,
                'nombre' => $role->nombre,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
