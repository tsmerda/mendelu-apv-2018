<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

$app->get('/login', function (Request $request, Response $response, $args) {
    return $this->view->render($response, 'login.latte');
})->setName('login');

$app->post('/login', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    if(!empty($data['login']) && !empty($data['pass'])) {
        try {
            $stmt = $this->db->prepare('SELECT * FROM account
                                        WHERE login = :l');
            $stmt->bindValue(':l', $data['login']);
            $stmt->execute();
            $user = $stmt->fetch();
            if(!empty($user)) {
                if(password_verify($data['pass'], $user['password'])) {
                    $_SESSION['user'] = $user;
                    return $response->withHeader('Location',
                                $this->router->pathFor('persons'));
                }
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }
    }
    $tplVars['error'] = 'Chyba prihlaseni.';
    return $this->view->render($response, 'login.latte', $tplVars);
});