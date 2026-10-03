<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Devise (code ISO 4217, nom, symbole) proposée dans les formulaires admin et les taux de change. */
class Currency extends Model
{
    protected $fillable = ['code', 'name', 'symbol'];
}
