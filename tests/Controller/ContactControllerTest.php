<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Formulaire de contact : vrai POST Symfony. L'e-mail est « envoyé » vers un transport null
 * (rien ne part), les compteurs du limiteur sont en mémoire : aucune base de données, aucun réseau.
 */
class ContactControllerTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        // Le limiteur compte par adresse IP : une IP différente à chaque test évite toute influence
        // d'un test (ou d'un lancement précédent) sur un autre.
        $client->setServerParameter('REMOTE_ADDR', '10.' . random_int(1, 254) . '.' . random_int(1, 254) . '.' . random_int(1, 254));

        return $client;
    }

    private function formulaire(KernelBrowser $client, array $valeurs = [])
    {
        $crawler = $client->request('GET', '/contact');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton("ENVOYER L'ÉTINCELLE")->form();
        $form->setValues(['contact' => $valeurs + [
            'nom' => 'Camille Martin',
            'email' => 'camille@example.com',
            'sujet' => 'question',
            'message' => 'Bonjour, avez-vous une box sans noisette ?',
        ]]);

        return $form;
    }

    public function testEnvoiValideEnvoieUnEmailAvecReplyToEtAffichageDeConfirmation(): void
    {
        $client = $this->client();

        $client->submit($this->formulaire($client));

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHeaderSame($email, 'to', 'Perlecook@perline-cookies.com');
        $this->assertEmailHeaderSame($email, 'reply-to', 'Camille Martin <camille@example.com>');
        $this->assertEmailTextBodyContains($email, 'avez-vous une box sans noisette');
        $this->assertEmailHeaderSame($email, 'subject', '[Contact] Question sur une Box — Camille Martin');

        $this->assertResponseRedirects('/contact');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Votre message a bien été envoyé');
    }

    public function testDonneesInvalidesAffichentDesErreursEtNenvoientRien(): void
    {
        $client = $this->client();

        $client->submit($this->formulaire($client, ['nom' => 'A', 'email' => 'pas-un-email', 'message' => 'court']));

        $this->assertEmailCount(0);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Votre nom est trop court');
        $this->assertSelectorTextContains('body', 'Cette adresse e-mail n\'est pas valide');
        $this->assertSelectorTextContains('body', 'Votre message est trop court');
    }

    public function testMessageTropLongEstRefuse(): void
    {
        $client = $this->client();

        $client->submit($this->formulaire($client, ['message' => str_repeat('a', 5001)]));

        $this->assertEmailCount(0);
        $this->assertSelectorTextContains('body', 'trop long');
    }

    public function testJetonCsrfInvalideEstRefuse(): void
    {
        $client = $this->client();
        $form = $this->formulaire($client);
        $form['contact[_token]'] = 'jeton-falsifie';

        $client->submit($form);

        $this->assertEmailCount(0);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testChampPiegeRempliNEnvoieRienMaisRepondCommeSiToutAllaitBien(): void
    {
        $client = $this->client();

        $client->submit($this->formulaire($client, ['site_web' => 'http://spam.example']));

        $this->assertEmailCount(0);
        $this->assertResponseRedirects('/contact');
    }

    public function testLimitationDeDebitBloqueLeQuatriemeEnvoi(): void
    {
        $client = $this->client();

        for ($i = 1; $i <= 3; $i++) {
            $client->submit($this->formulaire($client));
            $this->assertResponseRedirects('/contact');
            $this->assertEmailCount(1, null, 'Envoi n°' . $i);
        }

        $client->submit($this->formulaire($client));

        $this->assertResponseStatusCodeSame(429);
        $this->assertEmailCount(0, null, 'Le quatrième message n\'est pas envoyé');
        $this->assertSelectorTextContains('body', 'Trop de messages');
    }

    public function testPageContactNaPlusLeFauxEnvoiJavaScript(): void
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/public/js/contact.js');

        $this->assertStringNotContainsString('preventDefault', $js);
        $this->assertStringNotContainsString('MESSAGE ENVOYÉ', $js);
    }
}
