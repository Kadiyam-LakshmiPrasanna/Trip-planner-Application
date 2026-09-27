<?php

header('Content-Type: application/json');

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

$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$age      = $data['age'] ?? '';
$gender   = trim($data['gender'] ?? '');

if ($name === '' || $email === '' || $password === '' || $age === '' || $gender === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Name, email, password, age and gender are all required."
    ]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Please enter a valid email address."
    ]);
    exit();
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Password must be at least 6 characters long."
    ]);
    exit();
}

// Age must be a real, sane whole number. This is the trusted value
// Join Community will later compare against a trip's Age Limit, so
// it is validated strictly here and never accepted again from the client.
if (!ctype_digit((string)$age) || (int)$age < 1 || (int)$age > 120) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Please enter a valid age between 1 and 120."
    ]);
    exit();
}
$age = (int)$age;

// Gender must be one of the recognized values. This is the trusted
// value Join Community will compare against a trip's Who Can Join setting.
$allowedGenders = ["Men", "Women", "Other"];
if (!in_array($gender, $allowedGenders, true)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Please select a valid gender option."
    ]);
    exit();
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Check if the email is already registered.
$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Email already exists."
    ]);
    exit();
}

$check->close();

// Insert the new user.
$stmt = $conn->prepare("INSERT INTO users (name, email, password, age, gender) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssis", $name, $email, $hashedPassword, $age, $gender);

try {
    $stmt->execute();
    echo json_encode([
        "status" => "success",
        "message" => "Registration Successful"
    ]);
} catch (mysqli_sql_exception $e) {
    // Covers the rare race where two requests with the same email pass the
    // duplicate check above at nearly the same time; the table's UNIQUE
    // constraint on email is the real guarantee against duplicates.
    if ($conn->errno === 1062) {
        http_response_code(409);
        echo json_encode([
            "status"  => "error",
            "message" => "Email already exists."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status"  => "error",
            "message" => "Registration Failed: " . $e->getMessage()
        ]);
    }
}

$stmt->close();
$conn->close();
