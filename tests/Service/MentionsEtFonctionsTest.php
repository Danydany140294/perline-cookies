<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Garde-fous : pas de fausse preuve sociale sur le site, fonctions optionnelles désactivées par défaut. */
class MentionsEtFonctionsTest extends TestCase
{
    public function testLesFonctionsOptionnellesSontDesactiveesParDefaut(): void
    {
        $yaml = Yaml::parseFile(dirname(__DIR__, 2) . '/config/boutique.yaml')['parameters'];

        foreach (['favoris', 'avis', 'points_fidelite'] as $fonction) {
            $this->assertArrayHasKey($fonction, $yaml['boutique']['fonctions']);
            $variable = 'env(BOUTIQUE_FONCTION_' . strtoupper($fonction) . ')';
            $this->assertSame('0', $yaml[$variable], "La fonction « $fonction » doit être désactivée par défaut");
        }
    }

    public function testAucuneFausseMentionChiffreeDansLesTemplates(): void
    {
        $interdits = '/\d[\d\s.,]*\+?\s*avis|\d+[,.]\d\s*\/\s*5|Cliente? vérifiée|★/u';
        $trouves = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() === 'twig' && preg_match($interdits, (string) file_get_contents($fichier->getPathname()), $m)) {
                $trouves[] = $fichier->getFilename() . ' : ' . $m[0];
            }
        }

        $this->assertSame([], $trouves, 'Mention chiffrée (avis, notes, étoiles) non adossée à des données réelles');
    }
}
