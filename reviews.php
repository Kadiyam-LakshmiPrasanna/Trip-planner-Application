<?php
/**
 * reviews.php
 *
 * GET  /reviews.php   -> list all reviews, sorted by rating (5★ first)
 *                        then by most recent within the same rating.
 *                        Only name, rating and text are returned —
 *                        email is stored but never exposed publicly.
 *
 * POST /reviews.php   -> submit a new review.
 *                        Body: { name, email, rating, review_text }
 */

header('Content-Type: application/json');

include "db.php";

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleListReviews($conn);
} elseif ($method === 'POST') {
    handleCreateReview($conn);
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}

$conn->close();


function handleListReviews($conn)
{
    $sql = "SELECT name, rating, review_text, created_at
            FROM reviews
            ORDER BY rating DESC, created_at DESC";
    $result = $conn->query($sql);

    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = [
            "name"        => $row['name'],
            "rating"      => (int) $row['rating'],
            "review_text" => $row['review_text'],
            "created_at"  => $row['created_at'],
        ];
    }

    echo json_encode([
        "status"  => "success",
        "reviews" => $reviews
    ]);
}


function handleCreateReview($conn)
{
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid request data."]);
        return;
    }

    $name       = trim($data['name'] ?? '');
    $email      = trim($data['email'] ?? '');
    $rating     = $data['rating'] ?? null;
    $reviewText = trim($data['review_text'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Please enter your name."]);
        return;
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Please enter a valid email address."]);
        return;
    }

    if (!is_numeric($rating) || (int) $rating < 1 || (int) $rating > 5) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "⚠️ Please select a rating between 1 and 5 stars."]);
        return;
    }
    $rating = (int) $rating;

    if ($reviewText === '' || mb_strlen($reviewText) > 1000) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Please write a review (up to 1000 characters)."]);
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO reviews (name, email, rating, review_text) VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "❌ Failed to prepare statement: " . $conn->error]);
        return;
    }

    $stmt->bind_param("ssis", $name, $email, $rating, $reviewText);

    if ($stmt->execute()) {
        echo json_encode([
            "status"  => "success",
            "message" => "✅ Review submitted successfully!",
            "id"      => $stmt->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "❌ Could not submit your review: " . $stmt->error]);
    }

    $stmt->close();
}
