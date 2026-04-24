<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Security\AdminAuth;
use PDO;
use Throwable;

final class AdminAuthController
{
    /**
     * @var array<string,mixed>
     */
    private array $app;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    public function login(): void
    {
        try {
            $db = $this->getDb();
            $this->ensureAdminUsersTable($db);

            $body = $this->readJsonBody();
            $username = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');

            if ($username === '' || $password === '') {
                $this->json(400, ['error' => 'username and password are required']);
                return;
            }

            $stmt = $db->prepare(
                'SELECT id, username, display_name, password_hash, role, status
                 FROM admin_users
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($user) || strtoupper((string) ($user['status'] ?? 'INACTIVE')) !== 'ACTIVE') {
                $this->json(401, ['error' => 'Invalid credentials']);
                return;
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                $this->json(401, ['error' => 'Invalid credentials']);
                return;
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            session_regenerate_id(true);

            $sessionUser = [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'display_name' => (string) ($user['display_name'] ?: $user['username']),
                'role' => (string) ($user['role'] ?? 'admin'),
            ];

            $_SESSION['admin_user'] = $sessionUser;

            $upd = $db->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
            $upd->execute([':id' => (int) $user['id']]);

            $this->json(200, ['data' => ['user' => $sessionUser]]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }

    public function me(): void
    {
        $user = AdminAuth::sessionUser();
        if ($user === null) {
            $this->json(401, ['error' => 'Unauthorized']);
            return;
        }

        $this->json(200, ['data' => ['user' => $user]]);
    }

    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }
        session_destroy();

        $this->json(200, ['data' => ['ok' => true]]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getDb(): PDO
    {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            throw new \RuntimeException('Database unavailable');
        }

        return $db;
    }

    private function ensureAdminUsersTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(80) NOT NULL,
                display_name VARCHAR(120) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(40) NOT NULL DEFAULT "admin",
                status ENUM("ACTIVE", "INACTIVE") NOT NULL DEFAULT "ACTIVE",
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_users_username (username),
                KEY idx_admin_users_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

