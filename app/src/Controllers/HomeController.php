<?php

class HomeController
{
    public function index(): void
    {
        $bookManager = new BookManager();
        $latestBooks = $bookManager->findLatest(4);

        $userManager = new UserManager();
        $owners = $userManager->findOwnersOf($latestBooks);

        require __DIR__ . '/../Views/home.php';
    }
}
