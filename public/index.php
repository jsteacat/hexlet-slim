<?php
// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use App\Middleware\RequestLoggerMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\UsersRepository;
use App\UserValidator;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    // Хранилище — $_SESSION, поэтому сессия должна быть запущена выше
    return new Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

// HTML-формы умеют отправлять только GET и POST, поэтому PATCH, PUT и DELETE
// приходят как POST с полем _METHOD. Разбор тела должен выполняться раньше
// подмены метода, поэтому добавляется последним (внешним) middleware.
$app->add(MethodOverrideMiddleware::class);
$app->addBodyParsingMiddleware();

// Собственные middleware подключаются так же, как встроенные.
// Slim выполняет их по принципу «матрёшки» (LIFO): последний добавленный
// оборачивает все остальные и выполняется первым. Поэтому RequestLoggerMiddleware
// добавлен последним — он измеряет время всего запроса целиком, включая
// остальные middleware, роутинг и обработчик маршрута.
$app->add(SecurityHeadersMiddleware::class);

$hashMiddleware = function (Request $request, RequestHandler $handler) {
    // Передаем управление следующей мидлваре или обработчику
    $response = $handler->handle($request);

    $hash = hash('sha256', (string) $response->getBody());

    // Меняем ответ, после того как приложение обработало запрос
    return $response->withHeader('X-Content-Hash', $hash);
};

$app->add($hashMiddleware);

$app->add(RequestLoggerMiddleware::class);

$repo = new UsersRepository();
$router = $app->getRouteCollector()->getRouteParser();

// Текущий пользователь берётся из сессии: при входе туда записывается id,
// при выходе — удаляется. Если пользователя удалили, пока id оставался
// в сессии, find() вернёт null и аутентификация считается отсутствующей.
$getCurrentUser = function () use ($repo): ?array {
    $id = $_SESSION['user_id'] ?? null;

    if (!is_int($id)) {
        return null;
    }

    return $repo->find($id);
};

$app->get('/', function ($request, $response) use ($router) {
    return $response->withRedirect($router->urlFor('users'));
});

// Аутентификация по email: достаточно знать email существующего пользователя.
$app->get('/login', function ($request, $response) {
    $params = [
        'values' => ['email' => ''],
        'errors' => [],
    ];

    return $this->get('renderer')->render($response, 'login.phtml', $params);
})->setName('login');

$app->post('/login', function ($request, $response) use ($repo, $router) {
    $body = $request->getParsedBody();
    $email = is_array($body) && is_string($body['email'] ?? null) ? trim($body['email']) : '';

    $errors = [];
    $user = null;

    if ($email === '') {
        $errors['email'] = 'Email is required';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Email is not valid';
    } else {
        $user = $repo->findByEmail($email);

        if ($user === null) {
            $errors['email'] = 'User with this email is not found';
        }
    }

    if ($errors !== []) {
        $params = [
            'values' => ['email' => $email],
            'errors' => $errors,
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'login.phtml', $params);
    }

    // Новая id сессии после повышения уровня доступа — защита от фиксации сессии.
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $this->get('flash')->addMessage('success', 'Logged in');

    return $response->withRedirect($router->urlFor('users'));
});

$app->post('/logout', function ($request, $response) use ($router) {
    // Сессию не уничтожаем: flash-сообщения хранятся в $_SESSION.
    unset($_SESSION['user_id']);
    $this->get('flash')->addMessage('success', 'Logged out');

    return $response->withRedirect($router->urlFor('users'));
})->setName('logout');

$app->get('/users', function ($request, $response) use ($repo, $getCurrentUser) {
    $flash = $this->get('flash')->getMessages();

    $users = $repo->all();

    $queryParams = $request->getQueryParams();
    $term = $queryParams['term'] ?? '';
    $term = is_string($term) ? $term : ''; // ?term[]=... иначе даст TypeError в str_contains

    $users = array_values(array_filter(
        $users,
        fn (array $user): bool => $term === '' || str_contains($user['nickname'], $term),
    ));

    usort($users, fn (array $left, array $right): int => $left['nickname'] <=> $right['nickname']);

    $params = [
        'flash' => $flash,
        'users' => $users,
        'term' => $term,
        'currentUser' => $getCurrentUser(),
    ];

    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('users');

$app->get('/users/new', function ($request, $response) {
    $params = [
        'values' => ['nickname' => '', 'email' => ''],
        'errors' => [],
    ];

    return $this->get('renderer')->render($response, 'new.phtml', $params);
});

$app->post('/users', function ($request, $response) use ($repo, $router) {
    $userData = $request->getParsedBody();

    $validator = new UserValidator();
    $result = $validator->validate(is_array($userData) ? $userData : []);

    if (count($result['errors']) === 0) {
        $repo->create($result['values']['nickname'], $result['values']['email']);
        $this->get('flash')->addMessage('success', 'User Added');

        return $response->withRedirect($router->urlFor('users'));
    }

    $params = [
        'values' => $result['values'],
        'errors' => $result['errors'],
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'new.phtml', $params);
});

// Указанный путь считается относительно базовой директории для шаблонов, заданной на этапе конфигурации
// $this доступен внутри анонимной функции благодаря https://php.net/manual/ru/closure.bindto.php
// $this в Slim это контейнер зависимостей
$app->get('/users/{id}', function ($request, $response, array $args) use ($repo) {
    $id = (int) $args['id'];
    $user = $repo->find($id);

    if ($user === null) {
        return $response->withStatus(404)->write('User not found');
    }

    $params = [
        'user' => $user,
        'flash' => $this->get('flash')->getMessages(),
    ];

    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('user');

$app->get('/users/{id}/edit', function ($request, $response, array $args) use ($repo) {
    $id = (int) $args['id'];
    $user = $repo->find($id);

    if ($user === null) {
        return $response->withStatus(404)->write('User not found');
    }

    $params = [
        'user' => $user,
        'values' => ['nickname' => $user['nickname'], 'email' => $user['email']],
        'errors' => [],
    ];

    return $this->get('renderer')->render($response, 'edit.phtml', $params);
})->setName('editUser');

$app->patch('/users/{id}', function ($request, $response, array $args) use ($repo, $router) {
    $id = (int) $args['id'];
    $user = $repo->find($id);

    if ($user === null) {
        return $response->withStatus(404)->write('User not found');
    }

    $userData = $request->getParsedBody();

    $validator = new UserValidator();
    $result = $validator->validate(is_array($userData) ? $userData : []);

    if (count($result['errors']) === 0) {
        $repo->update($id, $result['values']['nickname'], $result['values']['email']);
        $this->get('flash')->addMessage('success', 'User Updated');

        return $response->withRedirect($router->urlFor('user', ['id' => $id]));
    }

    $params = [
        'user' => $user,
        'values' => $result['values'],
        'errors' => $result['errors'],
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'edit.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, array $args) use ($repo, $router) {
    $id = (int) $args['id'];

    if (!$repo->delete($id)) {
        return $response->withStatus(404)->write('User not found');
    }

    $this->get('flash')->addMessage('success', 'User Deleted');

    return $response->withRedirect($router->urlFor('users'));
});

// Отдача существующих файлов встроенным сервером PHP (php -S), мимо Slim
if (PHP_SAPI === 'cli-server') {
    $path = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

    if ($path !== false && is_file($path) && str_starts_with($path, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$app->run();
