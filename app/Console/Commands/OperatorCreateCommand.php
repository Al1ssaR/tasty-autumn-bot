<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class OperatorCreateCommand extends Command
{
    protected $signature = 'operator:create {--name=} {--email=}';

    protected $description = 'Создать активную учётную запись оператора';

    public function handle(): int
    {
        $name = $this->stringOptionOrAsk('name', 'Имя оператора');
        $email = $this->stringOptionOrAsk('email', 'Email оператора');
        $password = (string) $this->secret('Пароль (минимум 12 символов)');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $this->info('Оператор создан.');

        return self::SUCCESS;
    }

    private function stringOptionOrAsk(string $option, string $question): string
    {
        $value = $this->option($option);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : (string) $this->ask($question);
    }
}
