<?php

// Turn on mysqli exceptions so real DB errors surface instead of failing silently.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host     = 'sql300.infinityfree.com';
$username = 'if0_42703492';
$password = 'kAkQhdJXiOok6K';
$database = 'if0_42703492_trip_planner';

try {
    $conn = new mysqli($host, $username, $password, $database);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]));
}
