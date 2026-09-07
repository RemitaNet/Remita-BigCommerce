<?php
// src/services/Database.php

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            // Adjust database credentials according to your production infrastructure
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $db   = getenv('DB_NAME') ?: 'remita_plugin_db';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pdo = new PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                throw new \PDOException($e->getMessage(), (int)$e->getCode());
            }
        }
        return self::$pdo;
    }

    /**
     * Look up keys for a specific merchant store
     */
    public static function getMerchantCredentials(string $storeHash): ?array
    {
        $db = self::getConnection();
        $stmt = $db->prepare("SELECT bigcommerce_access_token, remita_secret_key, remita_base_url FROM merchants WHERE store_hash = :store_hash LIMIT 1");
        $stmt->execute(['store_hash' => $storeHash]);
        return $stmt->fetch() ?: null;
    }
}
