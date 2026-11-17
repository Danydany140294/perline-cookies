<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Liens de vérification d'adresse e-mail (inscription et changement d'adresse) : URL signée (HMAC) qui porte l'identifiant du
 * compte, l'adresse concernée, le type et une date d'expiration (24 h). Aucun jeton n'est stocké : la signature fait foi.
 */
class VerificationEmail
{
    public const TYPE_INSCRIPTION = 'inscription';
    public const TYPE_CHANGEMENT = 'changement';
    private const VALIDITE_SECONDES = 86400;

    public function __construct(
        private UriSigner $signataire,
        private UrlGeneratorInterface $urls,
        private MailerInterface $mailer,
        private Environment $twig,
        private IdentiteBoutique $boutique,
    ) {
    }

    public function lien(User $user, string $type, string $email, ?int $maintenant = null): string
    {
        $url = $this->urls->generate('app_verifier_email', [
            'id' => $user->getId(),
            'type' => $type,
            'email' => $email,
            'expires' => ($maintenant ?? time()) + self::VALIDITE_SECONDES,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signataire->sign($url);
    }

    public function envoyerInscription(User $user): void
    {
        $this->envoyer(
            (string) $user->getEmail(),
            'Confirmez votre adresse e-mail',
            'emails/verification_email.html.twig',
            ['user' => $user, 'lien' => $this->lien($user, self::TYPE_INSCRIPTION, (string) $user->getEmail()), 'titre' => 'Bienvenue ! Confirmez votre adresse e-mail', 'texte' => 'Pour activer votre compte, confirmez votre adresse e-mail en cliquant sur le bouton ci-dessous.']
        );
    }

    public function envoyerChangement(User $user, string $nouvelleAdresse): void
    {
        $this->envoyer(
            $nouvelleAdresse,
            'Confirmez votre nouvelle adresse e-mail',
            'emails/verification_email.html.twig',
            ['user' => $user, 'lien' => $this->lien($user, self::TYPE_CHANGEMENT, $nouvelleAdresse), 'titre' => 'Confirmez votre nouvelle adresse e-mail', 'texte' => 'Vous avez demandé à utiliser cette adresse pour votre compte. Confirmez-la en cliquant sur le bouton ci-dessous : tant que ce n\'est pas fait, votre ancienne adresse reste active.']
        );
    }

    /** Notification de sécurité (sans lien) envoyée à une adresse : mot de passe modifié, adresse changée... */
    public function notifier(string $adresse, string $sujet, string $titre, string $texte): void
    {
        $this->envoyer($adresse, $sujet, 'emails/notification_securite.html.twig', ['titre' => $titre, 'texte' => $texte]);
    }

    /** La signature est valide ET le lien n'a pas expiré. */
    public function verifier(string $urlComplete, ?int $expires, ?int $maintenant = null): bool
    {
        return $expires !== null && $expires >= ($maintenant ?? time()) && $this->signataire->check($urlComplete);
    }

    private function envoyer(string $a, string $sujet, string $gabarit, array $contexte): void
    {
        $this->mailer->send(
            (new Email())
                ->from($this->boutique->expediteur())
                ->to($a)
                ->subject($sujet)
                ->html($this->twig->render($gabarit, $contexte))
        );
    }
}
