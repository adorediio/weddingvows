<?php
function getPdo(): PDO{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
<<<<<<< Updated upstream
            "mysql:host=127.0.0.1;port=3306;dbname=divorce_db;charset=utf8mb4",
=======
            "mysql:host=127.0.0.1;dbname=divorce_db;charset=utf8mb4",
>>>>>>> Stashed changes
            "root",   // XAMPP username
            "",       // XAMPP password is empty
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,       // show MySQL errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC   // rows come back with column names
            ]
        );
    }
    return $pdo;

}