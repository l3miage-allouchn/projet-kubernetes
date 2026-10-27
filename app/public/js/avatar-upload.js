// Mon compte : dès qu'une nouvelle photo de profil est choisie, on envoie le formulaire
// (le lien "modifier" ouvre la fenêtre de choix de fichier, comme sur la maquette)
const avatarInput = document.getElementById('avatar');

if (avatarInput !== null) {
    avatarInput.addEventListener('change', function () {
        avatarInput.form.submit();
    });
}
