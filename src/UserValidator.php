<?php

declare(strict_types=1);

namespace App;

final class UserValidator
{
    public const NICKNAME_MAX_LENGTH = 40;

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     values: array{nickname: string, email: string},
     *     errors: array<string, string>
     * }
     */
    public function validate(array $input): array
    {
        $nickname = is_string($input['nickname'] ?? null) ? trim($input['nickname']) : '';
        $email = is_string($input['email'] ?? null) ? trim($input['email']) : '';

        $errors = [];

        if ($nickname === '') {
            $errors['nickname'] = 'Nickname is required';
        } elseif (mb_strlen($nickname) > self::NICKNAME_MAX_LENGTH) {
            $errors['nickname'] = 'Nickname must be no longer than ' . self::NICKNAME_MAX_LENGTH . ' characters';
        }

        if ($email === '') {
            $errors['email'] = 'Email is required';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email is not valid';
        }

        return [
            'values' => ['nickname' => $nickname, 'email' => $email],
            'errors' => $errors,
        ];
    }
}
