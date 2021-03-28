<?php

session_start();

$_SESSION['test'] = 'Hello world!';
$_SESSION['rand'] = rand(1, 1000);