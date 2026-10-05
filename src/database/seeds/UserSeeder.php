<?php

namespace nsql\database\seeds;

use nsql\database\Nsql;

class UserSeeder
{
    public function run(Nsql $db): void
    {
        $users = [
            [
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
            ],
            [
                'username' => 'test',
                'email' => 'test@example.com',
                'password' => password_hash('test123', PASSWORD_DEFAULT),
            ],
        ];

        foreach ($users as $user) {
            $db->insert(
                "INSERT INTO users (username, email, password) VALUES (:username, :email, :password)",
                $user
            );
        }
    }
}
