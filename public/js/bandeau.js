// ========================================
// BANDEAU DE MESSAGE (même style que les messages flash de base.html.twig)
// afficherBandeau('Stock insuffisant ...', 'error' | 'warning' | 'success')
// Non bloquant, se ferme au clic ou après quelques secondes. Remplace alert().
// ========================================
(function () {
    var COULEURS = { success: 'bg-green-600', warning: 'bg-amber-500', error: 'bg-red-600' };

    window.afficherBandeau = function (message, type, dureeMs) {
        if (!message) { return; }

        var conteneur = document.getElementById('bandeau-messages');
        if (!conteneur) {
            conteneur = document.createElement('div');
            conteneur.id = 'bandeau-messages';
            conteneur.className = 'fixed top-24 left-1/2 -translate-x-1/2 z-[9999] flex flex-col gap-2 w-[90%] max-w-xl';
            document.body.appendChild(conteneur);
        }

        var bandeau = document.createElement('div');
        bandeau.setAttribute('role', 'alert');
        bandeau.title = 'Cliquer pour fermer';
        bandeau.className = 'cursor-pointer rounded-lg px-4 py-3 text-white shadow-lg ' + (COULEURS[type] || COULEURS.error);
        bandeau.style.whiteSpace = 'pre-line'; // plusieurs messages (un par ligne)
        bandeau.textContent = message;          // textContent : jamais de HTML injecté
        bandeau.addEventListener('click', function () { bandeau.remove(); });
        conteneur.appendChild(bandeau);

        setTimeout(function () { bandeau.remove(); }, dureeMs || 8000);
    };
})();
