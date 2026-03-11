<?php

class Database
{
    private static ?PDO $instance = null;
    private static string $configFile = __DIR__ . '/../config.json';

    // Keys that live in config.json (DB connection only)
    private static array $fileKeys = ['host', 'port', 'dbname', 'username', 'password'];

    // Keys that live in the database settings table
    private static array $dbKeys = ['anthropic_api_key', 'openai_api_key', 'venice_api_key', 'image_provider', 'freesound_api_key'];

    public static function getFileConfig(): array
    {
        $defaults = [
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'quests',
            'username' => 'root',
            'password' => ''
        ];
        if (!file_exists(self::$configFile)) {
            return $defaults;
        }
        $data = json_decode(file_get_contents(self::$configFile), true) ?: [];
        return array_merge($defaults, array_intersect_key($data, $defaults));
    }

    public static function getConfig(): array
    {
        $config = self::getFileConfig();
        // Merge in DB settings if connection is available
        try {
            $pdo = self::getConnection();
            $config = array_merge($config, self::getDbSettings($pdo));
        } catch (\Exception $e) {
            // DB not available yet — return file config with empty API key defaults
            foreach (self::$dbKeys as $key) {
                $config[$key] = $key === 'image_provider' ? 'openai' : '';
            }
        }
        return $config;
    }

    public static function getDbSettings(?PDO $pdo = null): array
    {
        $defaults = [
            'anthropic_api_key' => '',
            'openai_api_key' => '',
            'venice_api_key' => '',
            'image_provider' => 'openai',
            'freesound_api_key' => ''
        ];
        try {
            $pdo = $pdo ?? self::getConnection();
            // Check if settings table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
            if (!$stmt->fetch()) return $defaults;
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Exception $e) {
            // ignore
        }
        return $defaults;
    }

    public static function saveDbSettings(array $settings, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? self::getConnection();
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $value) {
            if (in_array($key, self::$dbKeys)) {
                $stmt->execute([$key, $value]);
            }
        }
    }

    public static function saveConfig(array $config): bool
    {
        // Save DB connection fields to file
        $fileConfig = array_intersect_key($config, array_flip(self::$fileKeys));
        $saved = file_put_contents(self::$configFile, json_encode($fileConfig, JSON_PRETTY_PRINT)) !== false;

        // Save API keys to database
        try {
            self::saveDbSettings($config);
        } catch (\Exception $e) {
            // DB may not be ready yet during initial setup
        }

        return $saved;
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = self::getFileConfig();
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['dbname']
            );
            self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        }
        return self::$instance;
    }

    public static function testConnection(array $config): array
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $config['host'],
                $config['port']
            );
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if database exists
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($config['dbname']));
            $dbExists = $stmt->fetch() !== false;

            if (!$dbExists) {
                $pdo->exec("CREATE DATABASE " . $config['dbname'] . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                return ['success' => true, 'message' => 'Connection successful. Database "' . $config['dbname'] . '" created.'];
            }

            return ['success' => true, 'message' => 'Connection successful. Database "' . $config['dbname'] . '" exists.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }
}
