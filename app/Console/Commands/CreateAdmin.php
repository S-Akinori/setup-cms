<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'cms:create-admin {email} {--name=Administrator} {--password=}';

    protected $description = 'CMS管理者を安全に作成または更新する';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('8文字以上のパスワード');
        if (! is_string($password) || mb_strlen($password) < 8) {
            $this->error('パスワードは8文字以上にしてください。');

            return self::FAILURE;
        }
        $user = User::updateOrCreate(['email' => mb_strtolower($this->argument('email'))], [
            'name' => $this->option('name'), 'password' => Hash::make($password), 'is_admin' => true,
        ]);
        $this->info("管理者 {$user->email} を作成しました。");

        return self::SUCCESS;
    }
}
