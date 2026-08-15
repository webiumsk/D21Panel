<?php

namespace App\Enums;

/**
 * RegWatch phase-1 topic taxonomy (docs/LEGAL.md): VAT registration and
 * thresholds, cross-border B2B reverse charge, the OSS regime, US LLC income
 * in SK/CZ returns, corporate/personal income tax, and document archiving.
 */
enum RegWatchTopic: string
{
    case VatRegistration = 'vat_registration';
    case ReverseCharge = 'reverse_charge';
    case Oss = 'oss';
    case UsLlcIncome = 'us_llc_income';
    case IncomeTax = 'income_tax';
    case Archiving = 'archiving';
}
