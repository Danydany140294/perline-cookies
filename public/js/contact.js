// ========================================
// CONTACT : animation des champs
// (l'envoi est un vrai POST Symfony, géré côté serveur : validation, CSRF, e-mail)
// ========================================
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.input-group input, .input-group textarea, .input-group select').forEach(function (champ) {
        champ.addEventListener('focus', function () {
            this.parentElement.style.transform = 'translateY(-2px)';
        });
        champ.addEventListener('blur', function () {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });

    // Évite le double envoi : le bouton est désactivé une fois le formulaire parti
    var formulaire = document.getElementById('future-form');
    if (formulaire) {
        formulaire.addEventListener('submit', function () {
            var bouton = formulaire.querySelector('.btn-send');
            if (bouton) {
                bouton.style.pointerEvents = 'none';
                bouton.querySelector('span').textContent = 'ENVOI EN COURS... ✨';
            }
        });
    }
});
