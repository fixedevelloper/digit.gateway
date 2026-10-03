<?php

namespace App\Enums;

enum CountryServiceStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Manual = 'MANUAL';
}
