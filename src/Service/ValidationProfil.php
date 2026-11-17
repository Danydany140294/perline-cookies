<?php

namespace App\Service;

/** Validation côté serveur des coordonnées modifiables dans le compte (nom, prénom, téléphone, date de naissance). */
class ValidationProfil
{
    public const TELEPHONE = '/^(?:(?:\+|00)33[ .-]?|0)[1-9](?:[ .-]?\d{2}){4}$/';
    public const NOM = '/^[\p{L}\p{M}][\p{L}\p{M}\' .-]{0,99}$/u';

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{valeurs: array{nom: string, prenom: string, telephone: ?string, dateNaissance: ?\DateTimeImmutable}, erreurs: array<string, string>}
     */
    public function valider(array $donnees, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $erreurs = [];
        $valeurs = ['nom' => '', 'prenom' => '', 'telephone' => null, 'dateNaissance' => null];

        foreach (['nom' => 'Le nom', 'prenom' => 'Le prénom'] as $champ => $libelle) {
            $v = $this->texte($donnees[$champ] ?? null);
            if ($v === '') {
                $erreurs[$champ] = "$libelle est obligatoire.";
            } elseif (!preg_match(self::NOM, $v)) {
                $erreurs[$champ] = "$libelle contient des caractères non autorisés ou dépasse 100 caractères.";
            }
            $valeurs[$champ] = $v;
        }

        $tel = $this->texte($donnees['telephone'] ?? null);
        if ($tel !== '') {
            if (!preg_match(self::TELEPHONE, $tel)) {
                $erreurs['telephone'] = 'Numéro de téléphone français invalide (ex. 06 12 34 56 78).';
            }
            $valeurs['telephone'] = $tel;
        }

        $date = $this->texte($donnees['dateNaissance'] ?? null);
        if ($date !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                $erreurs['dateNaissance'] = 'Date de naissance invalide.';
            } elseif ($d > $maintenant || $d < new \DateTimeImmutable('1900-01-01')) {
                $erreurs['dateNaissance'] = 'La date de naissance doit être comprise entre 1900 et aujourd\'hui.';
            } else {
                $valeurs['dateNaissance'] = $d;
            }
        }

        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }

    /** Chaîne nettoyée (espaces superflus, caractères de contrôle retirés) ; tout ce qui n'est pas du texte devient « ». */
    private function texte(mixed $valeur): string
    {
        if (!is_string($valeur)) {
            return '';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valeur) ?? '');
    }
}
