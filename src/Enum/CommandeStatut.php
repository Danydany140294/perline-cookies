<?php

namespace App\Enum;

enum CommandeStatut: string
{
    case EN_ATTENTE = 'en_attente';
    case PAYEE = 'payee';
    case CONFIRMEE = 'confirmee';
    case EN_PREPARATION = 'en_preparation';
    case EXPEDIEE = 'expediee';
    case LIVREE = 'livree';
    case ANNULEE = 'annulee';
    /** Paiement reçu mais écart (montant, devise...) à contrôler à la main : jamais supprimable */
    case A_VERIFIER = 'a_verifier';
    /** Remboursée entièrement (le remboursement se fait dans le tableau de bord Stripe ; le webhook charge.refunded met à jour) */
    case REMBOURSEE = 'remboursee';

    public function getLibelle(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'En attente',
            self::PAYEE => 'Payée',
            self::CONFIRMEE => 'Confirmée',
            self::EN_PREPARATION => 'En préparation',
            self::EXPEDIEE => 'Expédiée',
            self::LIVREE => 'Livrée',
            self::ANNULEE => 'Annulée',
            self::A_VERIFIER => 'À vérifier',
            self::REMBOURSEE => 'Remboursée',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'badge-warning',
            self::PAYEE => 'badge-success',
            self::CONFIRMEE => 'badge-info',
            self::EN_PREPARATION => 'badge-primary',
            self::EXPEDIEE => 'badge-secondary',
            self::LIVREE => 'badge-success',
            self::ANNULEE => 'badge-danger',
            self::A_VERIFIER => 'badge-danger',
            self::REMBOURSEE => 'badge-secondary',
        };
    }
}
