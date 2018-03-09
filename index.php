<?php
require_once('src/Crawler.php');

// DEBUG
error_reporting(E_ALL);
$path = isset($_GET['path']) ? $_GET['path'] : '';


$crawler = new Crawler($path, array(
    'root_folder' => 'pictures',
    'limit' => 5
));

$crawler->crawl();





