<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('user:create')]
#[Description('Create a new user account')]
class CreateUser extends Command
{
    public function handle(): int
    {
        intro('Create a new user account');

        $name = text(
            label: 'Name',
            required: 'A name is required.',
        );

        $email = text(
            label: 'Email',
            required: 'An email address is required.',
            validate: ['email' => ['email', 'unique:users,email']],
        );

        $password = password(
            label: 'Password',
            hint: 'Minimum 8 characters.',
            required: 'A password is required.',
            validate: fn (string $value) => strlen($value) < 8
                ? 'The password must be at least 8 characters.'
                : null,
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        outro("User {$user->email} created successfully.");

        return self::SUCCESS;
    }
}
