<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Добавляет к каждому ответу защитные HTTP-заголовки.
 * Работает «на выходе»: сначала получает готовый ответ от всех нижних слоёв,
 * затем дорабатывает его и возвращает наверх.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Запрещает браузеру «угадывать» Content-Type по содержимому ответа
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        // Запрещает показывать страницы проекта внутри чужого <iframe>
        $response = $response->withHeader('X-Frame-Options', 'DENY');
        // Не отправлять Referer при переходе с HTTPS-страницы на HTTP-сайт
        $response = $response->withHeader('Referrer-Policy', 'no-referrer-when-downgrade');

        return $response;
    }
}
