<?php
require_once('src/Salamander.php');

// DEBUG
error_reporting(E_ALL);
$path = isset($_GET['path']) ? $_GET['path'] : '';


$crawler = new Salamander($path, array(
    'images_dir' => 'photos',
    'thumbnails_dir' => 'thumbnails',
    'cache_dir' => 'cache',
    'limit' => 5
));

$crawler->crawl();





