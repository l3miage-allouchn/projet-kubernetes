// Formulaire d'un livre : dès qu'une photo est choisie, on l'affiche à la place de l'ancienne
// (l'image n'est envoyée au serveur qu'au clic sur "Valider")
const imageInput = document.getElementById('image');
const imagePreview = document.getElementById('image-preview');

if (imageInput !== null && imagePreview !== null) {
    imageInput.addEventListener('change', function () {
        const file = imageInput.files[0];

        if (file !== undefined) {
            imagePreview.src = URL.createObjectURL(file);
        }
    });
}
