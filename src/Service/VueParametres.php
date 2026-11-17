<?php

namespace App\Service;

/**
 * Vue en lecture seule d'un groupe de paramètres pour Twig (`boutique.nom_marque`, `livraison.seuil_gratuite_centimes`...).
 * Les valeurs sont lues À LA DEMANDE (défaut des fichiers de configuration + surcharges de l'écran « Paramètres de la boutique »).
 *
 * @implements \ArrayAccess<string, mixed>
 */
class VueParametres implements \ArrayAccess
{
    public function __construct(private ParametresBoutique $parametres, private string $groupe)
    {
    }

    /** @return array<string, mixed> */
    private function valeurs(): array
    {
        return match ($this->groupe) {
            'livraison' => $this->parametres->livraison(),
            'retrait' => $this->parametres->retrait(),
            default => $this->parametres->boutique(),
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->valeurs());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->valeurs()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Les paramètres se modifient dans l\'administration.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Les paramètres se modifient dans l\'administration.');
    }
}
