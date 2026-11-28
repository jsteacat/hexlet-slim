<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Пользователи хранятся в cookie браузера: в проекте пока нет базы данных.
 * Cookie — это пользовательский ввод, поэтому значение проверяется при каждом
 * чтении: отсутствующая или битая cookie приводит к новому сиду.
 * Стартовый список берётся из data/users.json.
 */
final class UsersRepository
{
    private const COOKIE_NAME = 'users';

    private const SEED_FILE = __DIR__ . '/../data/users.json';

    /**
     * Список на текущий запрос. Cookie нельзя перечитать в том же запросе,
     * поэтому изменения сначала попадают сюда, а затем синхронно пишутся в cookie.
     *
     * @var list<array{id: int, nickname: string, email: string}>
     */
    private array $users;

    public function __construct()
    {
        $users = self::decode($_COOKIE[self::COOKIE_NAME] ?? null);

        if ($users === null) {
            $users = self::seed();
            self::write($users);
        }

        $this->users = $users;
    }

    /**
     * @return list<array{id: int, nickname: string, email: string}>
     */
    public function all(): array
    {
        return array_values($this->users);
    }

    /**
     * @return array{id: int, nickname: string, email: string}|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, nickname: string, email: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        foreach ($this->users as $user) {
            if ($user['email'] === $email) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, nickname: string, email: string}
     */
    public function create(string $nickname, string $email): array
    {
        $user = ['id' => $this->nextId(), 'nickname' => $nickname, 'email' => $email];
        $this->users[] = $user;
        self::write($this->users);

        return $user;
    }

    /**
     * @return array{id: int, nickname: string, email: string}|null
     */
    public function update(int $id, string $nickname, string $email): ?array
    {
        foreach ($this->users as $key => $user) {
            if ($user['id'] === $id) {
                $updated = ['id' => $id, 'nickname' => $nickname, 'email' => $email];
                $this->users[$key] = $updated;
                self::write($this->users);

                return $updated;
            }
        }

        return null;
    }

    public function delete(int $id): bool
    {
        foreach ($this->users as $key => $user) {
            if ($user['id'] === $id) {
                unset($this->users[$key]);
                $this->users = array_values($this->users);
                self::write($this->users);

                return true;
            }
        }

        return false;
    }

    private function nextId(): int
    {
        $maxId = 0;

        foreach ($this->users as $user) {
            $maxId = max($maxId, $user['id']);
        }

        return $maxId + 1;
    }

    /**
     * Разбирает значение cookie. Возвращает null, если cookie отсутствует
     * или её структура не соответствует ожидаемой.
     *
     * @return list<array{id: int, nickname: string, email: string}>|null
     */
    private static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $users = [];

        foreach ($decoded as $item) {
            if (
                !is_array($item)
                || !is_int($item['id'] ?? null)
                || !is_string($item['nickname'] ?? null)
                || !is_string($item['email'] ?? null)
            ) {
                return null;
            }

            $users[] = ['id' => $item['id'], 'nickname' => $item['nickname'], 'email' => $item['email']];
        }

        return $users;
    }

    /**
     * @param list<array{id: int, nickname: string, email: string}> $users
     */
    private static function write(array $users): void
    {
        $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode users for cookie');
        }

        setcookie(self::COOKIE_NAME, $json, [
            // Cookie живёт до закрытия браузера — как раньше жила PHP-сессия.
            'expires' => 0,
            // Пустой path делает cookie видимой только в каталоге текущего запроса.
            'path' => '/',
            // JS на странице эти данные не нужны — закрываем доступ из клиентских скриптов.
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Стартовый список пользователей берётся из data/users.json — там та же
     * структура, что и в cookie. Файл — часть проекта, поэтому его отсутствие
     * или битое содержимое это ошибка конфигурации, а не повод молча пересидеть.
     *
     * @return list<array{id: int, nickname: string, email: string}>
     */
    private static function seed(): array
    {
        $raw = file_get_contents(self::SEED_FILE);

        if ($raw === false) {
            throw new RuntimeException('Users seed file is missing or not readable: ' . self::SEED_FILE);
        }

        $users = self::decode($raw);

        if ($users === null) {
            throw new RuntimeException('Users seed file has invalid content: ' . self::SEED_FILE);
        }

        return $users;
    }
}
