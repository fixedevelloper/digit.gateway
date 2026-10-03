<?php

namespace App\Enums;

enum TransferService: string
{
    case MobileMoney = 'MOBILE_MONEY';
    case BankTransfer = 'BANK_TRANSFER';
}
