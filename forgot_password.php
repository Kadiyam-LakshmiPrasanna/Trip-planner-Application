<?php
// filepath: c:\xampp\htdocs\trip planner\forgot_password.php

header('Content-Type: application/json');
include "db.php";

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');

if ($email === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email is required."]);
    exit;
}

$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 3600);

    $update = $conn->prepare(
        "UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?"
    );
    $update->bind_param("ssi", $tokenHash, $expires, $user['id']);
    $update->execute();

    $resetLink = "http://localhost/trip%20planner/reset_password.html?token=" . urlencode($token);

    $subject = "Trip Planner Password Reset";
    $message = "Hello {$user['name']},\n\nReset your password using this link:\n$resetLink\n\nThis link expires in one hour.";
    $headers = "From: no-reply@tripplanner.com";

    mail($email, $subject, $message, $headers);
}

echo json_encode([
    "status" => "success",
    "message" => "If that email exists, a password reset link has been sent."
]);