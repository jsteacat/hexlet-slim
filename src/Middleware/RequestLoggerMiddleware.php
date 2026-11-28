<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Логирует каждый HTTP-запрос: метод, адрес, код ответа и время обработки.
 * Одна строка на запрос, лог пишется в data/requests.log.
 */
final class RequestLoggerMiddleware implements MiddlewareInterface
{
    private const LOG_FILE = __DIR__ . '/../../data/requests.log';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        // Запускаем всё, что дальше по цепочке: другие middleware, роутинг, обработчик маршрута.
        // Пока handle() не вернул ответ, мы стоим «до» приложения — это и есть модель «матрёшки».
        $response = $handler->handle($request);

        $duration = microtime(true) - $start;
        $line = sprintf(
            "[%s] %s %s -> %d (%.2f ms)\n",
            date('Y-m-d H:i:s'),
            $request->getMethod(),
            $request->getUri()->getPath(),
            $response->getStatusCode(),
            $duration * 1000,
        );

        // FILE_APPEND — дописать в конец файла, а не затереть его; LOCK_EX — только одна запись за раз.
        file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);

        return $response;
    }
}
