<?php

namespace App\Tests\Controller;

use App\Controller\AdresseController;
use App\Service\AdresseService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** « Mes Adresses » dans le tableau de bord : une seule vue, un seul contrôleur (retour dans l'onglet quand la demande vient de là). */
class AdresseOngletTest extends TestCase
{
    private function controleur(): AdresseController
    {
        $routeur = $this->createMock(UrlGeneratorInterface::class);
        $routeur->method('generate')->willReturnCallback(static fn (string $nom): string => match ($nom) {
            'app_user_dashboard' => '/user/mon-compte',
            'app_adresses_index' => '/compte/adresses',
            default => '/autre',
        });
        $controleur = new AdresseController($this->createMock(AdresseService::class));
        $controleur->setContainer(new ServiceLocator(['router' => static fn () => $routeur]));

        return $controleur;
    }

    private function retour(Request $requete): RedirectResponse
    {
        $methode = new \ReflectionMethod(AdresseController::class, 'retour');

        return $methode->invoke($this->controleur(), $requete);
    }

    public function testDepuisLOngletOnRetourneDansLOnglet(): void
    {
        $reponse = $this->retour(Request::create('/compte/adresses/5/supprimer?depuis=compte', 'POST'));

        $this->assertSame('/user/mon-compte#adresses', $reponse->getTargetUrl());
    }

    public function testDepuisLaPageOnRetourneSurLaPage(): void
    {
        $this->assertSame('/compte/adresses', $this->retour(Request::create('/compte/adresses/5/supprimer', 'POST'))->getTargetUrl());
        $this->assertSame('/compte/adresses', $this->retour(Request::create('/compte/adresses?depuis=ailleurs', 'POST'))->getTargetUrl(), 'Valeur inconnue : comportement normal');
    }

    public function testUneSeuleVueAdressesPartageeParLaPageEtLOnglet(): void
    {
        $racine = dirname(__DIR__, 2) . '/templates';
        $vue = file_get_contents($racine . '/compte/_adresses_vue.html.twig');
        $page = file_get_contents($racine . '/compte/adresses.html.twig');
        $onglet = file_get_contents($racine . '/user/mon_compte.html.twig');

        $this->assertStringContainsString('form_start(addressForm', $vue, 'Le formulaire est dans la vue partagée');
        foreach (['page' => $page, 'onglet' => $onglet] as $nom => $source) {
            $this->assertStringContainsString("compte/_adresses_vue.html.twig", $source, "La $nom inclut la vue partagée");
            $this->assertStringNotContainsString('form_start(', $source, "La $nom ne contient aucune copie du formulaire");
            $this->assertStringNotContainsString('adresses-list', $source, "La $nom ne contient aucune copie de la liste");
        }
    }
}
