<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'services', 'active'];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'active' => 'boolean',
        ];
    }

    public function supports(string $service): bool
    {
        return $this->services === null || in_array($service, $this->services, true);
    }
}
