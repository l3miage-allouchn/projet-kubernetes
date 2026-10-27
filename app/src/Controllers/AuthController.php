<?php

class AuthController
{
    public function register(): void
    {
        $pseudo = '';
        $email = '';
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // ?? '' : si un champ manque dans la requête, pas de warning PHP
            $pseudo = trim($_POST['pseudo'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $error = Validator::validateUser($pseudo, $email, $password, true);

            $userManager = new UserManager();

            if ($error === null && $userManager->findByEmail($email) !== null) {
                $error = 'Cet email est déjà utilisé.';
            }

            if ($error === null) {
                $user = new User();
                $user->setPseudo($pseudo);
                $user->setEmail($email);
                $user->setPassword(password_hash($password, PASSWORD_DEFAULT));

                $id = $userManager->create($user);
                $user->setId($id);

                // nouvel identifiant de session à la connexion : protège contre la fixation de session
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user->getId();
                $_SESSION['user_pseudo'] = $user->getPseudo();

                header('Location: /tomtroc/public/');
                return;
            }
        }

        require __DIR__ . '/../Views/auth/register.php';
    }

    public function login(): void
    {
        $email = '';
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($email === '' || $password === '') {
                $error = 'Veuillez remplir tous les champs.';
            } else {
                $userManager = new UserManager();
                $user = $userManager->findByEmail($email);

                if ($user === null || !password_verify($password, $user->getPassword())) {
                    $error = 'Email ou mot de passe incorrect.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user->getId();
                    $_SESSION['user_pseudo'] = $user->getPseudo();

                    header('Location: /tomtroc/public/');
                    return;
                }
            }
        }

        require __DIR__ . '/../Views/auth/login.php';
    }

    public function logout(): void
    {
        session_destroy();
        header('Location: /tomtroc/public/');
    }
}
