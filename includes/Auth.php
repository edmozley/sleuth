<?php

class Auth
{
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function getProfileId(): ?int
    {
        self::init();
        return isset($_SESSION['profile_id']) ? (int)$_SESSION['profile_id'] : null;
    }

    public static function requireProfile(): int
    {
        self::init();
        $id = $_SESSION['profile_id'] ?? null;
        if (!$id) {
            // API request — return JSON error
            if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'not_logged_in']);
                exit;
            }
            // Page request — redirect to profile picker
            header('Location: profiles.php');
            exit;
        }
        return (int)$id;
    }

    public static function getProfile(): ?array
    {
        $id = self::getProfileId();
        if (!$id) return null;
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function setProfile(int $id): void
    {
        self::init();
        $_SESSION['profile_id'] = $id;
    }

    public static function logout(): void
    {
        self::init();
        unset($_SESSION['profile_id']);
    }
}
