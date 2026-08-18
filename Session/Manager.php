<?php

declare(strict_types=1);

namespace CodeX\Http\Session;

use CodeX\Contract\Container;
use CodeX\Http\Session\Handler\Database;
use CodeX\Http\Session\Handler\File;
use CodeX\Http\Session\Handler\Memcached;
use CodeX\Http\Session\Handler\Redis;
use RuntimeException;
use SessionHandlerInterface;
use SensitiveParameter;

class Manager
{
    private string $driver;
    private array $config;
    private bool $started = false;

    public function __construct(
        private readonly Container $container,
        array $config = []
    ) {
        $this->config = array_merge([
            'driver' => 'file',
            'lifetime' => 7200,
            'path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codex_sessions',
            'name' => 'CODEX_SESSION',
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => null, // null = автоопределение
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ], $config);

        $this->driver = strtolower($this->config['driver']);
    }

    public function regenerate(): bool
    {
        if (!$this->started) {
            $this->start();
        }

        $result = session_regenerate_id(true);

        if (!$result) {
            error_log('Session: не удалось регенерировать ID сессии.');
        }

        return $result;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $handler = $this->createHandler();
        session_set_save_handler($handler, true);

        session_name($this->config['name']);

        // Автоопределение HTTPS
        $isHttps = $this->config['cookie_secure'] ?? (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['cookie_path'],
            'domain' => $this->config['cookie_domain'],
            'secure' => $isHttps,
            'httponly' => $this->config['cookie_httponly'],
            'samesite' => $this->config['cookie_samesite'],
        ]);

        // Критически важные настройки безопасности
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0'); // Запрет передачи ID в URL
        ini_set('session.cookie_httponly', '1');
        ini_set('session.lazy_write', '1');

        // Отключение встроенного GC для не-native драйверов
        if ($this->driver !== 'native') {
            ini_set('session.gc_probability', '0');
            ini_set('session.gc_divisor', '1');
        }

        session_start();
        $this->started = true;

        // Защита от Session Fixation: регенерация ID для новых сессий
        if (!isset($_SESSION['_initialized'])) {
            if (session_regenerate_id(true)) {
                $_SESSION['_initialized'] = true;
            } else {
                error_log('Session: не удалось регенерировать ID при инициализации.');
                $_SESSION['_initialized'] = true; // Помечаем, чтобы не пытаться снова
            }
        }
    }

    private function createHandler(): SessionHandlerInterface
    {
        return match ($this->driver) {
            'file' => new File(
                $this->config['path'],
                $this->config['lifetime']
            ),
            'database' => $this->createDatabaseHandler(),
            'redis' => $this->createRedisHandler(),
            'memcached' => $this->createMemcachedHandler(),
            default => throw new RuntimeException('Неподдерживаемый драйвер сессий: ' . $this->driver),
        };
    }

    private function createDatabaseHandler(): Database
    {
        $manager = $this->container->make(\CodeX\DataBase\Connection\Manager::class);
        $pdo = $manager->connection();

        return new Database(
            $pdo,
            $this->config['table'] ?? 'sessions',
            $this->config['lifetime']
        );
    }

    private function createRedisHandler(): Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('Расширение Redis не установлено.');
        }

        $redis = new \Redis();
        $redis->connect(
            $this->config['host'] ?? '127.0.0.1',
            $this->config['port'] ?? 6379
        );

        if (!empty($this->config['password'])) {
            $redis->auth($this->config['password']);
        }

        if (isset($this->config['database'])) {
            $redis->select((int)$this->config['database']);
        }

        return new Redis(
            $redis,
            $this->config['lifetime'],
            $this->config['prefix'] ?? 'codex_session:'
        );
    }

    private function createMemcachedHandler(): Memcached
    {
        if (!class_exists(\Memcached::class)) {
            throw new RuntimeException('Расширение Memcached не установлено.');
        }

        $memcached = new \Memcached();
        $memcached->addServer(
            $this->config['host'] ?? '127.0.0.1',
            $this->config['port'] ?? 11211
        );

        return new Memcached(
            $memcached,
            $this->config['lifetime'],
            $this->config['prefix'] ?? 'codex_session:'
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started) {
            $this->start();
        }

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->started) {
            $this->start();
        }

        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (!$this->started) {
            $this->start();
        }

        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        if (!$this->started) {
            $this->start();
        }

        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        if (!$this->started) {
            $this->start();
        }

        return $_SESSION;
    }

    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            // ИСПРАВЛЕНО: Современный синтаксис с массивом опций (PHP 7.3+)
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->started = false;
    }

    public function addFlash(string $type, mixed $message): void
    {
        if (!$this->started) {
            $this->start();
        }

        $flash = $_SESSION['_flash'] ?? [];

        if (!is_array($flash)) {
            $flash = [];
        }

        $flash[$type][] = $message;

        $_SESSION['_flash'] = $flash;
    }

    public function getFlash(?string $type = null): array
    {
        if (!$this->started) {
            $this->start();
        }

        // ИСПРАВЛЕНО: Работа через локальную переменную для устранения
        // ложных срабатываний статических анализаторов
        $flash = $_SESSION['_flash'] ?? null;

        if (!is_array($flash) || $flash === []) {
            unset($_SESSION['_flash']);
            return [];
        }

        if ($type === null) {
            unset($_SESSION['_flash']);
            return $flash;
        }

        if (!array_key_exists($type, $flash)) {
            return [];
        }

        $messages = $flash[$type];

        unset($flash[$type]);

        if ($flash === []) {
            unset($_SESSION['_flash']);
        } else {
            $_SESSION['_flash'] = $flash;
        }

        return is_array($messages) ? $messages : [$messages];
    }

    public function hasFlash(?string $type = null): bool
    {
        if (!$this->started) {
            $this->start();
        }

        $flash = $_SESSION['_flash'] ?? null;

        if (!is_array($flash) || $flash === []) {
            return false;
        }

        if ($type === null) {
            return true;
        }

        if (!array_key_exists($type, $flash)) {
            return false;
        }

        $messages = $flash[$type];

        if (is_array($messages)) {
            return $messages !== [];
        }

        return $messages !== null;
    }

    /**
     * Устанавливает подписанную cookie для Remember Me.
     *
     * @param string $name Имя cookie
     * @param string $value Значение (например, токен или user_id)
     * @param int $lifetime Время жизни в секундах
     * @param string $secret Секретный ключ для HMAC-подписи
     */
    public function setRememberCookie(
        string $name,
        string $value,
        int $lifetime,
        #[SensitiveParameter] string $secret
    ): void {
        if (!$this->started) {
            $this->start();
        }

        // ИСПРАВЛЕНО: Подпись значения для защиты от подделки (Tampering)
        $signature = hash_hmac('sha256', $value, $secret);
        $signedValue = base64_encode($value) . '|' . $signature;

        $isHttps = $this->config['cookie_secure'] ?? (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        setcookie($name, $signedValue, [
            'expires' => time() + $lifetime,
            'path' => $this->config['cookie_path'],
            'domain' => $this->config['cookie_domain'],
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}