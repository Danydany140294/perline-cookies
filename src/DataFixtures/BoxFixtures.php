<?php

namespace App\DataFixtures;

use App\Entity\Box;
use App\Entity\Produit;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class BoxFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Récupérer les produits depuis la base
        $produitRepository = $manager->getRepository(Produit::class);
        $pistacheCannelle = $produitRepository->findOneBy(['name' => 'Cookie Pistache Cannelle']);
        $pecheCaramel = $produitRepository->findOneBy(['name' => 'Cookie Pêche Caramel']);
        $noisetteVanille = $produitRepository->findOneBy(['name' => 'Cookie Noisette Vanille']);
        $macadamiaFramboise = $produitRepository->findOneBy(['name' => 'Cookie Macadamia Framboise']);
        $fruitsRouges = $produitRepository->findOneBy(['name' => 'Cookie Fruits Rouges']);

        // Box 1 : Gourmande (fixe)
        $box1 = new Box();
        $box1->setNom('Box Gourmande');
        $box1->setDescription('Une sélection premium de nos cookies les plus gourmands : Macadamia Framboise, Pêche Caramel et Pistache Cannelle. Pour les vrais amateurs de saveurs raffinées.');
        $box1->setPrix(24.90);
        $box1->setStock(50);
        $box1->setImage('box-gourmande.jpg');
        $box1->setType('fixe');
        // Associer les produits
        if ($macadamiaFramboise) $box1->ajouterCookie($macadamiaFramboise, 1);
        if ($pecheCaramel) $box1->ajouterCookie($pecheCaramel, 1);
        if ($pistacheCannelle) $box1->ajouterCookie($pistacheCannelle, 1);
        $manager->persist($box1);

        // Box 2 : Découverte (fixe)
        $box2 = new Box();
        $box2->setNom('Box Découverte');
        $box2->setDescription('Découvrez l\'ensemble de notre gamme avec cette box contenant nos 5 cookies phares : Pistache Cannelle, Pêche Caramel, Noisette Vanille, Macadamia Framboise et Fruits Rouges.');
        $box2->setPrix(19.90);
        $box2->setStock(60);
        $box2->setImage('box-decouverte.jpg');
        $box2->setType('fixe');
        // Associer tous les produits
        if ($pistacheCannelle) $box2->ajouterCookie($pistacheCannelle, 1);
        if ($pecheCaramel) $box2->ajouterCookie($pecheCaramel, 1);
        if ($noisetteVanille) $box2->ajouterCookie($noisetteVanille, 1);
        if ($macadamiaFramboise) $box2->ajouterCookie($macadamiaFramboise, 1);
        if ($fruitsRouges) $box2->ajouterCookie($fruitsRouges, 1);
        $manager->persist($box2);

        // Box 3 : Surprise (fixe)
        $box3 = new Box();
        $box3->setNom('Box Surprise');
        $box3->setDescription('Laissez-vous surprendre ! Chaque mois, une nouvelle sélection de 6 cookies incluant nos créations exclusives et nos nouvelles recettes. Un voyage gustatif garanti !');
        $box3->setPrix(22.90);
        $box3->setStock(40);
        $box3->setImage('box-surprise.jpg');
        $box3->setType('fixe');
        // Produits aléatoires/du mois
        if ($fruitsRouges) $box3->ajouterCookie($fruitsRouges, 1);
        if ($noisetteVanille) $box3->ajouterCookie($noisetteVanille, 1);
        if ($pistacheCannelle) $box3->ajouterCookie($pistacheCannelle, 1);
        $manager->persist($box3);

        // Box 4 : Personnalisable
        $box4 = new Box();
        $box4->setNom('Box Personnalisable');
        $box4->setDescription('Composez votre propre box ! Choisissez 6, 12 ou 24 cookies parmi toute notre gamme selon vos envies et vos préférences.');
        $box4->setPrix(19.90);
        $box4->setStock(100);
        $box4->setImage('box-personnalisable.jpg');
        $box4->setType('personnalisable');
        // Pas de produits associés : le client choisit
        $manager->persist($box4);

        // Box 5 : Prestige (fixe)
        $box5 = new Box();
        $box5->setNom('Box Prestige');
        $box5->setDescription('L\'excellence à l\'état pur. Une box luxueuse avec nos cookies d\'exception préparés avec des ingrédients nobles : chocolat Valrhona, pistaches de Sicile, noix de macadamia premium. Le cadeau idéal.');
        $box5->setPrix(34.90);
        $box5->setStock(30);
        $box5->setImage('box-prestige.jpg');
        $box5->setType('fixe');
        // Les meilleurs produits
        if ($macadamiaFramboise) $box5->ajouterCookie($macadamiaFramboise, 1);
        if ($pistacheCannelle) $box5->ajouterCookie($pistacheCannelle, 1);
        $manager->persist($box5);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProduitFixtures::class,
        ];
    }
}