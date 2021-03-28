<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

$app->get('/', function(Request $request, Response $response) {
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
});

$app->group('/auth', function() use($app) {
    include('routes-person.php');

    $app->get('/logout', function(Request $request, Response $response) {
        session_destroy();
        return $response->withHeader('Location', $this->router->pathFor('login'));
    })->setName('logout');
})->add(function(Request $request, Response $response, $next) {
    if(!empty($_SESSION['user'])) {
        return $next($request, $response);
    } else {
        return $response->withHeader('Location', $this->router->pathFor('login'));
    }
});

include('routes-login.php');