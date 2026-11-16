<?php

namespace App\Controller;

use App\Form\ContactType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\IdentiteBoutique;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private RateLimiterFactory $contactLimiter,
        private IdentiteBoutique $boutique
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Limitation de débit : chaque envoi (valide ou non) compte, par adresse IP.
            $limite = $this->contactLimiter->create($request->getClientIp() ?? 'inconnue')->consume();
            if (!$limite->isAccepted()) {
                $attente = max(1, (int) ceil(($limite->getRetryAfter()->getTimestamp() - time()) / 60));
                $this->addFlash('error', sprintf('Trop de messages envoyés. Merci de réessayer dans %d minute(s).', $attente));

                // Le client ne perd pas sa saisie, et aucune erreur de validation n'est affichée :
                // on réaffiche un formulaire NEUF (non soumis, donc non validé) prérempli avec ce qu'il a tapé.
                return $this->render(
                    'Page/contact.html.twig',
                    ['form' => $this->createForm(ContactType::class, $this->saisieDe($request))],
                    new Response(status: Response::HTTP_TOO_MANY_REQUESTS)
                );
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();

            // Champ piège rempli = robot : on répond comme si tout allait bien, sans rien envoyer.
            if (trim((string) ($donnees['site_web'] ?? '')) !== '') {
                $this->logger->warning('Formulaire de contact : champ piège rempli, message ignoré.', ['ip' => $request->getClientIp()]);
                $this->addFlash('success', 'Merci ! Votre message a bien été envoyé. Nous vous répondrons rapidement.');

                return $this->redirectToRoute('app_contact');
            }

            try {
                $this->mailer->send($this->construireEmail($donnees));
                $this->addFlash('success', 'Merci ! Votre message a bien été envoyé. Nous vous répondrons rapidement.');

                return $this->redirectToRoute('app_contact');
            } catch (\Throwable $e) {
                $this->logger->error('Formulaire de contact : échec de l\'envoi de l\'e-mail.', ['erreur' => $e->getMessage()]);
                $this->addFlash('error', 'Votre message n\'a pas pu être envoyé. Merci de réessayer dans quelques instants.');
            }
        }

        return $this->render('Page/contact.html.twig', ['form' => $form], new Response(
            status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK
        ));
    }

    /**
     * Ce que le client a saisi (champs texte uniquement, tronqués), pour le lui réafficher tel quel.
     *
     * @return array<string, string>
     */
    private function saisieDe(Request $request): array
    {
        $envoye = $request->request->all('contact');
        $saisie = [];

        foreach (['nom' => 100, 'email' => 180, 'sujet' => 20, 'message' => 5000] as $champ => $longueurMax) {
            $valeur = $envoye[$champ] ?? null;
            if (is_string($valeur) && $valeur !== '') {
                $saisie[$champ] = mb_substr($valeur, 0, $longueurMax);
            }
        }

        return $saisie;
    }

    /** @param array{nom: string, email: string, sujet: string, message: string} $donnees */
    private function construireEmail(array $donnees): Email
    {
        // Pas de retour à la ligne dans les en-têtes (injection d'en-têtes) : on les neutralise.
        $nom = trim(preg_replace('/[\r\n\t]+/', ' ', $donnees['nom']));
        $sujet = array_search($donnees['sujet'], ContactType::SUJETS, true) ?: 'Contact';

        return (new Email())
            ->from($this->boutique->expediteur())
            ->to($this->boutique->emailContact())
            ->replyTo(new Address($donnees['email'], $nom))
            ->subject(sprintf('[Contact] %s — %s', $sujet, $nom))
            ->text(sprintf(
                "Message reçu depuis le formulaire de contact\n\nDe : %s <%s>\nSujet : %s\n\n%s\n",
                $nom,
                $donnees['email'],
                $sujet,
                $donnees['message']
            ));
    }
}
