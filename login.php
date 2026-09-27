<?php

header('Content-Type: application/json');

session_start();

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request data."
    ]);
    exit();
}

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Email and password are required."
    ]);
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();

    if (password_verify($password, $user['password'])) {

        $_SESSION['user'] = $user['id'];

        echo json_encode([
            "status"  => "success",
            "message" => "Login Successful",
            "name"    => $user['name']
        ]);

    } else {

        echo json_encode([
            "status" => "error",
            "message" => "Wrong Password"
        ]);

    }

} else {

    echo json_encode([
        "status" => "error",
        "message" => "User Not Found"
    ]);

}

$stmt->close();
$conn->close();
?>