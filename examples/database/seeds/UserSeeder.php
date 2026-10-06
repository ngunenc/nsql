<?php

/**
 * Örnek seeder (paket içinde dağıtılmaz). Kendi seeds dizininize kopyalayın; şifreleri
 * gerçek ortamda kullanmayın.
 *
 *   $manager = new MigrationManager($db, seeds_path: __DIR__ . '/database/seeds');
 *   $manager->seed('UserSeeder');
 */

use nsql\database\Nsql;

return new class () {
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
                'INSERT INTO users (username, email, password) VALUES (:username, :email, :password)',
                $user
            );
        }
    }
};
