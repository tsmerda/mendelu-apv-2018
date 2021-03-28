<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


/**
 * STRÁNKA persons.latte
 */


$app->get('/', function (Request $request, Response $response, $args) {
    $q = $request->getQueryParam('q');
    try {
        if (empty($q)) {
            $stmt = $this->db->prepare('SELECT person.*, location.*, pocet_k, pocet_s
                FROM person
                LEFT JOIN location USING (id_location)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_k
                  FROM contact
                  GROUP BY id_person
                ) AS pocty_kontaktu USING (id_person)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_s
                  FROM person_meeting
                  GROUP BY id_person
                ) AS pocty_schuzek USING (id_person)
                ORDER BY last_name');
        } else {
            $stmt = $this->db->prepare('SELECT person.*, location.*, pocet_k, pocet_s
                FROM person
                LEFT JOIN location USING (id_location)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_k
                  FROM contact
                  GROUP BY id_person
                ) AS pocty_kontaktu USING (id_person)
                LEFT JOIN (
                  SELECT id_person, COUNT(*) AS pocet_s
                  FROM person_meeting
                  GROUP BY id_person
                ) AS pocty_schuzek USING (id_person)
                WHERE last_name ILIKE :q OR
                first_name ILIKE :q OR 
                nickname ILIKE :q
                ORDER BY last_name');
            $stmt->bindValue(':q', $q . '%');
        }
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $tplVars['people'] = $stmt->fetchAll();
    return $this->view->render($response, 'persons.latte', $tplVars);
})->setName('persons');

$app->post('/delete-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM person
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location',
        $this->router->pathFor('persons'));
})->setName('deletePerson');


/**
 * STRÁNKA edit-person.latte
 */


$app->get('/edit-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("SELECT * FROM person
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $person = $stmt->fetch();
    if (empty($person)) {
        die('Osoba nenalezena.');
    }
    $tplVars['id'] = $id;
    $tplVars['form'] = [
        'fn' => $person['first_name'],
        'ln' => $person['last_name'],
        'nn' => $person['nickname'],
        'h' => $person['height'],
        'g' => $person['gender'],
        'bd' => $person['birth_day']
    ];
    return $this->view->render($response, 'edit-person.latte', $tplVars);
})->setName('editPerson');

$app->post('/edit-person', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();
    if (!empty($data['fn']) && !empty($data['ln']) && !empty($data['nn'])) {
        try {
            $stmt = $this->db->prepare('UPDATE person SET
                first_name = :fn, last_name = :ln, nickname = :nn,
                gender = :g, height = :h, birth_day = :bd
              WHERE id_person = :id');
            $h = empty($data['h']) ? null : $data['h'];
            $g = empty($data['g']) ? null : $data['g'];
            $bd = empty($data['bd']) ? null : $data['bd'];
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':fn', $data['fn']);
            $stmt->bindValue(':ln', $data['ln']);
            $stmt->bindValue(':nn', $data['nn']);
            $stmt->bindValue(':g', $g);
            $stmt->bindValue(':h', $h);
            $stmt->bindValue(':bd', $bd);
            $stmt->execute();
        } catch (Exception $e) {
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tato osoba uz existuje.';
                $tplVars['form'] = $data;
                return $this->view->render($response, 'edit-person.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('editPerson') . '?id=' . $id);
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        $tplVars['form'] = $data;
        return $this->view->render($response, 'edit-person.latte', $tplVars);
    }
});


/**
 * STRÁNKA new-with-address.latte
 */


$app->get('/new-with-address', function (Request $request, Response $response, $args) {
    $tplVars['form'] = [
        'fn' => '', 'ln' => '', 'nn' => '', 'h' => 180,
        'g' => '', 'bd' => '', 'ci' => '', 'st' => '',
        'sn' => '', 'zip' => '', 'idl' => ''];

    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  WHERE city IS NOT NULL AND street_name IS NOT NULL
                                  ORDER BY city, street_name');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch (Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    return $this->view->render($response, 'new-with-address.latte', $tplVars);
})->setName('newWithAddress');

$app->post('/new-with-address', function (Request $request, Response $response, $args) {
    try {
        $stmt = $this->db->query('SELECT * FROM location
                                  WHERE city IS NOT NULL AND street_name IS NOT NULL
                                  ORDER BY city, street_name');
        $tplVars['locations'] = $stmt->fetchAll();
    } catch (Exception $ex) {
        $this->logger->error($ex->getMessage());
        die($ex->getMessage());
    }

    $data = $request->getParsedBody();
    if (!empty($data['fn']) && !empty($data['ln']) && !empty($data['nn'])) {
        try {
            $this->db->beginTransaction();

            $idLocation = null;
            if (!empty($data['ci'])) {
                $stmt = $this->db->prepare('INSERT INTO location
                  (city, street_name, street_number, zip)
                  VALUES
                  (:ci, :st, :sn, :zip)');
                $stmt->bindValue(':ci', $data['ci']);
                $stmt->bindValue(':st', empty($data['st']) ? null : $data['st']);
                $stmt->bindValue(':sn', empty($data['sn']) ? null : $data['sn']);
                $stmt->bindValue(':zip', empty($data['zip']) ? null : $data['zip']);
                $stmt->execute();
                $idLocation = $this->db->lastInsertId('location_id_location_seq');
            } else if (!empty($data['idl'])) {
                $idLocation = $data['idl'];
            }

            $stmt = $this->db->prepare('INSERT INTO person
                (id_location, birth_day, gender, first_name, last_name, nickname, height)
                VALUES
                (:idl, :bd, :g, :fn, :ln, :nn, :h)');

            $stmt->bindValue(':idl', $idLocation);

            $h = empty($data['h']) ? null : $data['h'];
            $g = empty($data['g']) ? null : $data['g'];
            $bd = empty($data['bd']) ? null : $data['bd'];

            $stmt->bindValue(':fn', $data['fn']);
            $stmt->bindValue(':ln', $data['ln']);
            $stmt->bindValue(':nn', $data['nn']);
            $stmt->bindValue(':g', $g);
            $stmt->bindValue(':h', $h);
            $stmt->bindValue(':bd', $bd);
            $stmt->execute();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tato osoba uz existuje.';
                $tplVars['form'] = $data;
                return $this->view->render($response, 'new-with-address.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('persons'));
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        $tplVars['form'] = $data;
        return $this->view->render($response, 'new-with-address.latte', $tplVars);
    }
});


/**
 * STRÁNKA person-info.latte
 */


$app->get('/person-info', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');

    try {
        $stmt = $this->db->prepare("SELECT * FROM person
                                    LEFT JOIN location USING (id_location)
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $person = $stmt->fetch();
    if (empty($person)) {
        die('Osoba nenalezena.');
    }
    $tplVars['id'] = $id;
    $tplVars['table'] = [

        'fn' => $person['first_name'],
        'ln' => $person['last_name'],
        'nn' => $person['nickname'],
        'h' => $person['height'],
        'g' => $person['gender'],
        'bd' => $person['birth_day'],

        'idl' => $person['id_location'],
        'c' => $person['city'],
        'sna' => $person['street_name'],
        'snu' => $person['street_number'],
        'z' => $person['zip'],
        'co' => $person['country'],
        'n' => $person['name']
    ];

    try {
        $stmt = $this->db->prepare('SELECT person.*, contact.*, contact_type.*
                                    FROM person
                                    LEFT JOIN contact USING (id_person)
                                    LEFT JOIN contact_type USING (id_contact_type)
                                    WHERE id_person = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $tplVars['contact'] = $stmt->fetchAll();

    /** $tplVars['if'] = [
     *  'ic' => $...['id_contact']  KDYŽ NEBUDOU ÚDAJE
     * ];*/

    try {
        $stmt = $this->db->prepare('SELECT person.*, relation.*, relation_type.*
                                    FROM person
                                    LEFT JOIN relation
                                    ON relation.id_person1 = person.id_person
                                    LEFT JOIN relation_type
                                    ON relation_type.id_relation_type = relation.id_relation_type
                                    WHERE id_person = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $tplVars['relation'] = $stmt->fetchAll();

    try {
        $stmt = $this->db->prepare('SELECT person.*, location.*, meeting.*, person_meeting.*
                                    FROM person
                                    LEFT JOIN person_meeting USING (id_person) 
                                    LEFT JOIN meeting
                                    ON meeting.id_meeting = person_meeting.id_meeting
                                    LEFT JOIN location
                                    ON location.id_location = meeting.id_location
                                    WHERE id_person = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $tplVars['meeting'] = $stmt->fetchAll();

    try {
        $stmt = $this->db->prepare("SELECT person.first_name, person.last_name, id_meeting, person.id_person
                                    FROM person_meeting
                                    LEFT JOIN person ON person.id_person = person_meeting.id_person
                                    ORDER BY last_name");
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['persons'] = $stmt->fetchAll();

    return $this->view->render($response, 'person-info.latte', $tplVars);
})->setName('personInfo');


/**
 * STRÁNKA edit-person-addr.latte
 */


$app->get('/edit-person-addr', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("SELECT * FROM person
                                    LEFT JOIN location USING (id_location)
                                    WHERE id_person = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    $person = $stmt->fetch();
    if (empty($person)) {
        die('Osoba nenalezena.');
    }
    $tplVars['id'] = $id;
    $tplVars['form'] = [
        'c' => $person['city'],
        'sna' => $person['street_name'],
        'snu' => $person['street_number'],
        'z' => $person['zip'],
        'co' => $person['country'],
        'n' => $person['name'],
        'idl' => $person['id_location']
    ];
    return $this->view->render($response, 'edit-person-addr.latte', $tplVars);
})->setName('editPersonAddr');

$app->post('/edit-person-addr', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();

    try {
        if (!empty($data['c'])) {

            $stmt = $this->db->prepare('UPDATE location SET
                city = :c, street_name = :sna, street_number = :snu,
                zip = :z, country = :co, name = :n FROM person
              WHERE person.id_person = :id AND person.id_location = location.id_location');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':c', $data['c']);
            $stmt->bindValue(':sna', $data['sna']);
            $stmt->bindValue(':snu', $data['snu']);
            $stmt->bindValue(':z', $data['z']);
            $stmt->bindValue(':co', $data['co']);
            $stmt->bindValue(':n', $data['n']);
            $stmt->execute();

        } else {

            $this->db->beginTransaction();

            $idLocation = null;
            if (!empty($data['c'])) {
                $stmt = $this->db->prepare('INSERT INTO location
                  (city, street_name, street_number, zip, country, name)
                  VALUES
                  (:c, :sna, :snu, :z, :co, :n)');
                $stmt->bindValue(':c', $data['c']);
                $stmt->bindValue(':sna', empty($data['sna']) ? null : $data['sna']);
                $stmt->bindValue(':snu', empty($data['snu']) ? null : $data['snu']);
                $stmt->bindValue(':z', empty($data['z']) ? null : $data['z']);
                $stmt->bindValue(':co', empty($data['co']) ? null : $data['co']);
                $stmt->bindValue(':n', empty($data['n']) ? null : $data['n']);
                $stmt->execute();
                $idLocation = $this->db->lastInsertId('location_id_location_seq');
            } else if (!empty($data['idl'])) {
                $idLocation = $data['idl'];
            }

            $stmt = $this->db->prepare('INSERT INTO person
                (id_location, id_person)
                VALUES
                ( :idl, :id)');
            $stmt->bindValue(':idl', $idLocation);
            $stmt->bindValue(':id', $id);

            $stmt->execute();

            $this->db->commit();
        }
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $response->withHeader('Location', $this->router->pathFor('editPersonAddr') . '?id=' . $id);
});


/**
 * STRÁNKA edit-person-contact.latte
 */


$app->get('/edit-person-contact', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare('SELECT person.*, contact.*, contact_type.*
                                    FROM person
                                    LEFT JOIN contact USING (id_person)
                                    LEFT JOIN contact_type USING (id_contact_type)
                                    WHERE id_person = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        $tplVars['id'] = $id;
        $tplVars['contact'] = $stmt->fetchAll();


        $stmt = $this->db->query('SELECT * FROM contact_type');
        $tplVars['contact_type'] = $stmt->fetchAll();

    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $this->view->render($response, 'edit-person-contact.latte', $tplVars);
})->setName('editPersonContact');

$app->post('/delete-contact', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $idp = $request->getQueryParam('idp');
    try {
        $stmt = $this->db->prepare("DELETE FROM contact
                                    WHERE id_contact = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location', $this->router->pathFor('editPersonContact') . '?id=' . $idp);
})->setName('deleteContact');

$app->post('/new-contact', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();

    if (!empty($data['idct']) && !empty($data['c'])) {

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO contact 
                                          (id_person, contact, id_contact_type)
                                          VALUES
                                          (:id, :c, :idct)
                                         ');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':c', $data['c']);
            $stmt->bindValue(':idct', $data['idct']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento kontakt uz existuje.';
                return $this->view->render($response, 'edit-person-contact.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('editPersonContact') . '?id=' . $id);
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-person-contact.latte', $tplVars);
    }
})->setName('newContact');


/**
 * STRÁNKA edit-meetings.latte
 */


$app->get('/edit-relations', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("SELECT relation.*, person.*, relation_type.*         
       FROM relation
       LEFT JOIN person ON relation.id_person2 = person.id_person
       LEFT JOIN relation_type USING (id_relation_type)
       WHERE id_person1 = :id AND person.id_person <> :id
       ORDER BY person.id_person
                                   ");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['id'] = $id;
    $tplVars['relation'] = $stmt->fetchAll();

    try {
        $stmt = $this->db->query('SELECT * FROM relation_type');
        $tplVars['relation_type'] = $stmt->fetchAll();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    try {
        $stmt = $this->db->query("SELECT * FROM person 
                                 ORDER BY last_name
                                   ");
        $tplVars['person2'] = $stmt->fetchAll();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $this->view->render($response, 'edit-relations.latte', $tplVars);
})->setName('editRelations');

$app->post('/delete-relation', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $idp = $request->getQueryParam('idp');
    try {
        $stmt = $this->db->prepare("DELETE FROM relation
                                     WHERE id_relation = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }
    return $response->withHeader('Location', $this->router->pathFor('editRelations') . '?id=' . $idp);
})->setName('deleteRelation');

$app->post('/new-relation', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();

    if (!empty($data['idrt']) && !empty($data['id2'])) {

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO relation
                                           (id_person1, id_person2, description, id_relation_type)
                                           VALUES
                                           (:id, :id2, :d, :idrt)
                                          ');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':id2', $data['id2']);
            $stmt->bindValue(':d', $data['d']);
            $stmt->bindValue(':idrt', $data['idrt']);
            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tento vztah uz existuje.';
                return $this->view->render($response, 'edit-relation.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('editRelations') . '?id=' . $id);
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-relation.latte', $tplVars);
    }
})->setName('newRelation');


/**
 * STRÁNKA edit-meetings.latte
 */


$app->get('/edit-meetings', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("SELECT person.*, person_meeting.*, meeting.*, location.*
                                    FROM person
                                    LEFT JOIN person_meeting USING (id_person)
                                    LEFT JOIN meeting
                                    ON meeting.id_meeting = person_meeting.id_meeting
                                    LEFT JOIN location
                                    ON location.id_location = meeting.id_location
                                    WHERE id_person = :id AND person.id_person = person_meeting.id_person");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['id'] = $id;
    $tplVars['meeting'] = $stmt->fetchAll();

    try {
            $stmt = $this->db->query("SELECT * FROM location
                            ORDER BY city");
            $tplVars['location'] = $stmt->fetchAll();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    try {
            $stmt = $this->db->query("SELECT * FROM meeting
                                  LEFT JOIN location USING (id_location)");
            $tplVars['allmeeting'] = $stmt->fetchAll();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $this->view->render($response, 'edit-meetings.latte', $tplVars);
})->setName('editMeetings');


$app->post('/delete-person-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $idp = $request->getQueryParam('idp');

    try {
        $stmt = $this->db->prepare("DELETE FROM person_meeting
                                    WHERE id_meeting = :id AND id_person = :idp
                                   ");
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':idp', $idp);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $response->withHeader('Location', $this->router->pathFor('editMeetings') . '?id=' . $idp);
})->setName('deletePersonMeeting');

$app->post('/new-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();

    if (!empty($data['s'])) {

        try {
            $this->db->beginTransaction();

            $idLocation = null;
            if (!empty($data['ci'])) {
                $stmt = $this->db->prepare('INSERT INTO location
                                          (city, street_name, street_number, zip)
                                          VALUES
                                          (:ci, :st, :sn, :zip)');
                $stmt->bindValue(':ci', $data['ci']);
                $stmt->bindValue(':st', empty($data['st']) ? null : $data['st']);
                $stmt->bindValue(':sn', empty($data['sn']) ? null : $data['sn']);
                $stmt->bindValue(':zip', empty($data['zip']) ? null : $data['zip']);
                $stmt->execute();
                $idLocation = $this->db->lastInsertId('location_id_location_seq');
            } else if (!empty($data['idl'])) {
                $idLocation = $data['idl'];
            }

            $idMeeting = null;
            $stmt = $this->db->prepare('INSERT INTO meeting
                                          (start, description, duration, id_location)
                                          VALUES
                                          (:s, :desc, :dur, :idl)');

            $stmt->bindValue(':s', $data['s']);
            $stmt->bindValue(':desc', $data['desc']);
            $stmt->bindValue(':dur', $data['dur']);
            $stmt->bindValue(':idl', $idLocation);
            $stmt->execute();
            $idMeeting = $this->db->lastInsertId('meeting_id_meeting_seq');

            $stmt = $this->db->prepare('INSERT INTO person_meeting
                                          (id_meeting, id_person)
                                          VALUES
                                          (:idm, :id)');

            $stmt->bindValue(':idm', $idMeeting);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $this->db->commit();


        } catch (Exception $e) {
            $this->db->rollback();
            if ($e->getCode() == 23505) {
                $tplVars['error'] = 'Tato schůzka už existuje.';
                return $this->view->render($response, 'edit-meetings.latte', $tplVars);
            } else {
                $this->logger->error($e->getMessage());
                die($e->getMessage());
            }
        }
        return $response->withHeader('Location', $this->router->pathFor('editMeetings') . '?id=' . $id);
    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
        return $this->view->render($response, 'edit-meetings.latte', $tplVars);

    }
})->setName('newMeeting');

$app->post('/add-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    $data = $request->getParsedBody();

    if (!empty($data['idm'])) {

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('INSERT INTO person_meeting
                                          (id_meeting, id_person)
                                          VALUES
                                          (:idm, :id)
                                         ');
            $stmt->bindValue(':idm', $data['idm']);
            $stmt->bindValue(':id', $id);

            $stmt->execute();

            $this->db->commit();

        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            die($e->getMessage());
        }

    } else {
        $tplVars['error'] = 'Vyplnte povinne udaje.';
    }
    return $response->withHeader('Location', $this->router->pathFor('editMeetings') . '?id=' . $id);
})->setName('addMeeting');


/**
 * STRÁNKA meetings.latte
 */


$app->get('/meetings', function (Request $request, Response $response, $args) {
    try {
        $stmt = $this->db->prepare("SELECT DISTINCT meeting.*, location.*
                                    FROM meeting
                                    LEFT JOIN person_meeting USING (id_meeting)
                                    LEFT JOIN location USING (id_location) ");
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    $tplVars['meetings'] = $stmt->fetchAll();

    return $this->view->render($response, 'meetings.latte', $tplVars);
})->setName('meetings');

$app->post('/delete-meeting', function (Request $request, Response $response, $args) {
    $id = $request->getQueryParam('id');
    try {
        $stmt = $this->db->prepare("DELETE FROM person_meeting
                                    WHERE id_meeting = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    try {
        $stmt = $this->db->prepare("DELETE FROM meeting
                                    WHERE id_meeting = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    } catch (Exception $e) {
        $this->logger->error($e->getMessage());
        die($e->getMessage());
    }

    return $response->withHeader('Location', $this->router->pathFor('meetings'));
})->setName('deleteMeeting');
