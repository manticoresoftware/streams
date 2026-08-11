<?php
include "scaler.php";
include "curl.php";
include "response.php";

if (isset($_GET['health'])){
    echo "OK";
    die();
}

$scaler = new Scaler();
$scaler->check();

?>
