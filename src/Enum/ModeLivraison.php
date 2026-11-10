<?php

namespace App\Enum;

enum ModeLivraison: string
{
    case LIVRAISON = 'livraison';
    case RETRAIT = 'retrait';

    public function getLibelle(): string
    {
        return match ($this) {
            self::LIVRAISON => 'Livraison à domicile',
            self::RETRAIT => 'Retrait sur place',
        };
    }
}
