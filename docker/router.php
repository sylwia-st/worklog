<?php

// Router wbudowanego serwera PHP: czcionki z public/fonts i obrazki z public/img podaje bezpośrednio (z długim cache),
// wszystko inne idzie do Symfony.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/(fonts/[a-z0-9-]+\.woff2|img/[a-z0-9-]+\.svg)$#', $path) && is_file(__DIR__.'/../public'.$path)) {
    header('Cache-Control: public, max-age=31536000, immutable');

    return false;
}

// runtime Symfony uruchamia plik z SCRIPT_FILENAME – przy routerze byłby to ten plik
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__).'/public/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';

require $_SERVER['SCRIPT_FILENAME'];
