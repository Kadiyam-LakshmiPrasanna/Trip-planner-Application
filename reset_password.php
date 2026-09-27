<?php
// filepath: c:\xampp\htdocs\trip planner\reset_password.php

header('Content-Type: application/json');
include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$token = $data['token'] ?? '';
$password = $data['password'] ?? '';

if ($token === '' || strlen($password) < 8) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "A valid token and password of at least 8 characters are required."
    ]);
    exit;
}

$tokenHash = hash('sha256', $token);

$stmt = $conn->prepare(
    "SELECT id FROM users
     WHERE reset_token_hash = ?
     AND reset_token_expires > NOW()"
);
$stmt->bind_param("s", $tokenHash);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired reset link."
    ]);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$update = $conn->prepare(
    "UPDATE users
     SET password = ?, reset_token_hash = NULL, reset_token_expires = NULL
     WHERE id = ?"
);
$update->bind_param("si", $passwordHash, $user['id']);
$update->execute();

echo json_encode([
    "status" => "success",
    "message" => "Password reset successfully."
]);