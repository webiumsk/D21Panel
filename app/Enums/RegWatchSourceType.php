<?php

namespace App\Enums;

/**
 * Kind of official source RegWatch monitors: a legal register publishing
 * consolidated law texts (Slov-Lex, e-Sbirka) or a tax administration
 * publishing guidance and news (Financna sprava SR, Financni sprava CR).
 */
enum RegWatchSourceType: string
{
    case LegalRegister = 'legal_register';
    case TaxAuthority = 'tax_authority';
}
