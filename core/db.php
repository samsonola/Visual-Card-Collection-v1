<?php

function db(){
    static $conn;

    if ($conn) return $conn;


    $conn = mysqli_connect(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME'],
    (int)($_ENV['DB_PORT'] ?? 3306)
);

    if (!$conn) {
        die('DB Connection failed: ' . mysqli_connect_error());
    }

    return $conn;
}