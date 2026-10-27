<?php

session_start();

require_once __DIR__ . '/../src/Core/autoload.php';

$router = new Router();

$router->add('GET', '/', function () {
    (new HomeController())->index();
});

$router->add('GET', '/inscription', function () {
    (new AuthController())->register();
});
$router->add('POST', '/inscription', function () {
    (new AuthController())->register();
});

$router->add('GET', '/connexion', function () {
    (new AuthController())->login();
});
$router->add('POST', '/connexion', function () {
    (new AuthController())->login();
});

$router->add('GET', '/deconnexion', function () {
    (new AuthController())->logout();
});

$router->add('GET', '/profil/{id}', function ($id) {
    (new UserController())->show((int) $id);
});

$router->add('GET', '/mon-compte', function () {
    (new UserController())->account();
});
$router->add('POST', '/mon-compte', function () {
    (new UserController())->account();
});

$router->add('GET', '/livre/ajouter', function () {
    (new BookController())->add();
});
$router->add('POST', '/livre/ajouter', function () {
    (new BookController())->add();
});

// après /livre/ajouter : sinon "ajouter" serait pris pour un {id}
$router->add('GET', '/livre/{id}', function ($id) {
    (new BookController())->detail((int) $id);
});

$router->add('GET', '/livre/{id}/modifier', function ($id) {
    (new BookController())->edit((int) $id);
});
$router->add('POST', '/livre/{id}/modifier', function ($id) {
    (new BookController())->edit((int) $id);
});

$router->add('POST', '/livre/{id}/supprimer', function ($id) {
    (new BookController())->delete((int) $id);
});

$router->add('GET', '/livres', function () {
    (new BookController())->list();
});

$router->add('GET', '/messagerie', function () {
    (new MessageController())->inbox();
});

$router->add('GET', '/messagerie/{id}', function ($id) {
    (new MessageController())->conversation((int) $id);
});
$router->add('POST', '/messagerie/{id}', function ($id) {
    (new MessageController())->conversation((int) $id);
});

$router->add('GET', '/mentions-legales', function () {
    (new PageController())->legalNotice();
});

$router->add('GET', '/confidentialite', function () {
    (new PageController())->privacy();
});

$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$uri = substr($requestUri, strlen($basePath));
if ($uri === '' || $uri === false) {
    $uri = '/';
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $uri);
