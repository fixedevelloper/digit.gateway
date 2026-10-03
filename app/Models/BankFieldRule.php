<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankFieldRule extends Model
{
    protected $fillable = ['country_id', 'field', 'required'];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }
}
