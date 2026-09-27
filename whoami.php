<?php
// Lightweight session-check endpoint.
// Used by create_trip.html to *display* the logged-in user's name in the
// read-only "Created By" field, and to bounce guests back to the home page.
// This value is for UI display only — create_trip.php independently
// re-derives the authenticated user from the session on submit and never
// trusts anything the client sends back.

session_start();
header('Content-Type: application/json');

include "db.php";

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode([
        "loggedIn" => false
    ]);
    exit();
}

$userId = (int) $_SESSION['user'];

$stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "loggedIn" => false
    ]);
    exit();
}

$user = $result->fetch_assoc();

echo json_encode([
    "loggedIn" => true,
    "id"       => (int)$user['id'],
    "name"     => $user['name']
]);

$stmt->close();
$conn->close();
