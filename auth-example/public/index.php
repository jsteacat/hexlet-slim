<?php

// Подключение автозагрузки через composer (vendor лежит в корне проекта)
require __DIR__ . '/../../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Список пользователей. Пароли не хранятся в открытом виде — только их bcrypt-хэши
// (заранее вычислены через password_hash: secret, superpass, strongpass).
// Так быстрее: не нужно заново хэшировать пароли на каждый запрос
$users = [
    ['name' => 'admin', 'passwordDigest' => '$2y$12$c3cXvaIWTa9kGwYr845a/OH3zWrfxNDezzaZTK.SlNUZ9Y3RKZpda'],
    ['name' => 'mike', 'passwordDigest' => '$2y$12$ZLIBMTnddIUFEbBMvsynhOYE1DZM1OB0MlOeR73N4YXS6mMwYfbjK'],
    ['name' => 'kate', 'passwordDigest' => '$2y$12$bHyoZRWjHvnYVKwiytNauOlM38gSvbIXWfVXBmIxjUG4/VVskugTK'],
];

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    // Хранилище — $_SESSION, поэтому сессия должна быть запущена выше
    return new Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

// BEGIN (write your solution here)

$app->get('/', function ($request, $response) {
    $params = [
        'flash' => $this->get('flash')->getMessages(),
        'currentUser' => $_SESSION['user'] ?? null,
    ];

    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->post('/session', function ($request, $response) use ($users) {
    $data = (array) ($request->getParsedBody()['user'] ?? []);
    // ?user[name][]=... иначе даст TypeError при сравнении
    $name = is_string($data['name'] ?? null) ? $data['name'] : '';
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';

    foreach ($users as $knownUser) {
        if ($knownUser['name'] === $name && password_verify($password, $knownUser['passwordDigest'])) {
            $_SESSION['user'] = ['name' => $knownUser['name']];

            return $response->withRedirect('/');
        }
    }

    $this->get('flash')->addMessage('error', 'Wrong password or name');

    return $response->withRedirect('/');
});

$app->delete('/session', function ($request, $response) {
    // Сессию не уничтожаем: flash-сообщения хранятся в $_SESSION
    unset($_SESSION['user']);

    return $response->withRedirect('/');
});

// END

$app->run();
