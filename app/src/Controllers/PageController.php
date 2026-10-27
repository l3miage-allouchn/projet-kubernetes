<?php

class PageController
{
    public function legalNotice(): void
    {
        require __DIR__ . '/../Views/pages/legal-notice.php';
    }

    public function privacy(): void
    {
        require __DIR__ . '/../Views/pages/privacy.php';
    }
}
