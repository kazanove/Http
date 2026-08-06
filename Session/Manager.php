<?php
declare(strict_types=1);

namespace CodeX\Http\Session;

use CodeX\Contract\Container;
use CodeX\Http\Session\Handler\Database;
use CodeX\Http\Session\Handler\File;
use CodeX\Http\Session\Handler\Memcached;
use CodeX\Http\Session\Handler\Redis;
use PDO;
use RuntimeException;
use Throwable;

class Manager
{
    private string $driver;
    private array $config;
    private bool $started = false;

    private(set) string|false $id = false;
    private ?Container $container;

    public function __construct(array $config = [], ?Container $container = null)
    {
        $this->container = $container;
        $this->driver = strtolower($config['driver'] ?? 'native');
        $this->config = $config[$this->driver] ?? [];
        $this->configureSessionIni();
    }

    private function configureSessionIni(): void
    {
        $lifetime = $this->config['lifetime'] ?? 1800;

        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');

        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.lazy_write', '1');

        if ($this->driver !== 'native') {
            ini_set('session.gc_probability', '0');
            ini_set('session.gc_divisor', '1');
        }
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    public function start(): bool
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return true;
        }

        $this->registerDriver();
        $this->started = session_start();

        if (!$this->started) {
            return false;
        }

        $this->id = session_id();

        if (!isset($_SESSION['_initialized'])) {
            if (session_regenerate_id(true)) {
                $this->id = session_id();
                $_SESSION['_initialized'] = true;
            } else {
                error_log('Не удалось регенерировать ID сессии при инициализации.');
                // Все равно помечаем как инициализированную, чтобы не пытаться
                // регенерировать при каждом запросе, если драйвер это не поддерживает.
                $_SESSION['_initialized'] = true;
            }
        }

        $last = $this->get('last_activity', 0);
        $lifetime = $this->config['lifetime'] ?? 1800;

        if ($last > 0 && (time() - $last) > $lifetime) {
            $this->destroy();
            return false;
        }

        $this->set('last_activity', time());
        return true;
    }

    private function registerDriver(): void
    {
        $handler = match ($this->driver) {
            'db' => $this->createDbHandler(),
            'redis' => $this->createRedisHandler(),
            'memcached' => $this->createMemcachedHandler(),
            'files' => $this->createFilesHandler(),
            'native' => null,
            default => throw new RuntimeException('Неподдерживаемый драйвер сессии: ' . $this->driver)
        };

        if ($handler !== null) {
            session_set_save_handler($handler, true);
        }
    }

    private function createDbHandler(): Database
    {
        $pdo = $this->config['pdo'] ?? null;

        if (!$pdo instanceof PDO && $this->container !== null) {
            try {
                $pdo = $this->container->make(PDO::class);
            } catch (Throwable) {
                // Игнорируем, выбросим ошибку ниже
            }
        }

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Для драйвера "db" необходим экземпляр PDO.');
        }

        return new Database($pdo, $this->config['table'] ?? 'sessions', $this->config['lifetime'] ?? 1800);
    }

    private function createRedisHandler(): Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('Расширение Redis (phpredis) не установлено.');
        }

        $redis = new \Redis();
        $redis->connect($this->config['host'] ?? '127.0.0.1', (int)($this->config['port'] ?? 6379), (float)($this->config['timeout'] ?? 0.0));

        if (!empty($this->config['password'])) {
            $redis->auth($this->config['password']);
        }

        if (isset($this->config['database'])) {
            $redis->select((int)$this->config['database']);
        }

        return new Redis($redis, $this->config['lifetime'] ?? 1800, $this->config['prefix'] ?? 'codex_session:');
    }

    private function createMemcachedHandler(): Memcached
    {
        if (!class_exists(\Memcached::class)) {
            throw new RuntimeException('Расширение Memcached не установлено.');
        }

        $mc = new \Memcached();
        $mc->addServer($this->config['host'] ?? '127.0.0.1', (int)($this->config['port'] ?? 11211));

        return new Memcached($mc, $this->config['lifetime'] ?? 1800, $this->config['prefix'] ?? 'codex_session:');
    }

    private function createFilesHandler(): File
    {
        $path = $this->config['path'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codex_sessions';
        return new File($path, $this->config['lifetime'] ?? 1800);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

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

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function regenerate(bool $deleteOldSession = true): bool
    {
        $this->ensureStarted();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $result = session_regenerate_id($deleteOldSession);

        if ($result) {
            $this->id = session_id();
        }

        return $result;
    }

    public function delete(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function getFlash(?string $type = null): array
    {
        $this->ensureStarted();

        $flash = $_SESSION['_flash'] ?? null;

        if (!is_array($flash) || $flash === []) {
            unset($_SESSION['_flash']);

            return [];
        }

        // Если тип не указан, возвращаем все flash-сообщения.
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
        $this->ensureStarted();

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

    public function addFlash(string $type, string $message): void
    {
        $this->ensureStarted();

        $flash = $_SESSION['_flash'] ?? [];

        if (!is_array($flash)) {
            $flash = [];
        }

        $flash[$type][] = $message;

        $_SESSION['_flash'] = $flash;
    }
}