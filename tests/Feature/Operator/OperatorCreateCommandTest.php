<?php

namespace Tests\Feature\Operator;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperatorCreateCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Operator command test requires PostgreSQL.');
        }
    }

    public function test_command_creates_active_operator_with_hashed_password(): void
    {
        $this->artisan('operator:create', [
            '--name' => 'Первый оператор',
            '--email' => 'operator@example.test',
        ])
            ->expectsQuestion('Пароль (минимум 12 символов)', 'StrongPassword123!')
            ->expectsOutput('Оператор создан.')
            ->assertSuccessful();

        $operator = User::query()->where('email', 'operator@example.test')->firstOrFail();
        $this->assertTrue($operator->is_active);
        $this->assertTrue(Hash::check('StrongPassword123!', $operator->password));
        $this->assertNotSame('StrongPassword123!', $operator->password);
    }
}
