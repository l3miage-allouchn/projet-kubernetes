// Mon compte : demande une confirmation avant de supprimer un livre
// (une suppression est définitive : un clic par erreur ne doit pas suffire)
const deleteForms = document.querySelectorAll('.js-confirm-delete');

deleteForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!confirm(form.dataset.confirm)) {
            // l'utilisateur a cliqué sur "Annuler" : on bloque l'envoi du formulaire
            event.preventDefault();
        }
    });
});
