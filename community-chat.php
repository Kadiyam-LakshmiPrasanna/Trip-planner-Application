<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    $redirect = isset($_GET['id']) ? 'community-chat.php?id=' . (int)$_GET['id'] : 'home.html';
    header('Location: home.html?login=1&redirect=' . urlencode($redirect));
    exit();
}

$user_id = (int)$_SESSION['user'];

if (!isset($_GET['id'])) {
    die("Community ID is missing.");
}

$community_id = (int)$_GET['id'];
$messagesTableExists = false;
$canChat = true;

$tableCheck = $conn->query("SHOW TABLES LIKE 'trip_members'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $membershipStmt = $conn->prepare("SELECT id FROM trip_members WHERE trip_id = ? AND user_id = ?");
    $membershipStmt->bind_param("ii", $community_id, $user_id);
    $membershipStmt->execute();
    $membershipResult = $membershipStmt->get_result();
    $canChat = $membershipResult->num_rows > 0;
}

$messagesTableCheck = $conn->query("SHOW TABLES LIKE 'community_messages'");
if ($messagesTableCheck && $messagesTableCheck->num_rows > 0) {
    $messagesTableExists = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');

    if ($message !== '' && $messagesTableExists && $canChat) {
        $sql = "INSERT INTO community_messages (community_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $community_id, $user_id, $message);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit();
    }

    echo json_encode(['status' => 'error']);
    exit();
}

$messages = [];
if ($messagesTableExists) {
    $sql = "
        SELECT
            cm.message,
            cm.created_at,
            cm.sender_id,
            u.name AS username
        FROM community_messages cm
        JOIN users u
            ON cm.sender_id = u.id
        WHERE cm.community_id = ?
        ORDER BY cm.created_at ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $community_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
}

$ajaxRequest = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);
if ($ajaxRequest) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'messages' => $messages]);
    exit();
}

?>

<!DOCTYPE html>
<html>

<head>

<title>Community Chat</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #eef4ff, #f8fcff);
    margin: 0;
    padding: 20px;
    color: #1f2937;
}

.chat-container {
    width: 640px;
    max-width: 95%;
    margin: 30px auto;
    background: #ffffff;
    padding: 24px;
    border-radius: 18px;
    box-shadow: 0 12px 35px rgba(37, 99, 235, 0.15);
}

.chat-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 12px;
    background: #e8f0ff;
    color: #2563eb;
    text-decoration: none;
    border-radius: 999px;
    font-weight: 600;
    transition: background 0.2s ease;
}

.back-link:hover {
    background: #dbeafe;
}

.chat-header h2 {
    margin: 0;
    color: #1e3a8a;
}

.messages {
    height: 400px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    background: #f9fbff;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 12px;
}

.message {
    margin-bottom: 14px;
    padding: 12px 14px;
    background: white;
    border-radius: 12px;
    border-left: 4px solid #60a5fa;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.username {
    font-weight: bold;
    color: #2563eb;
}

.message-text {
    margin-top: 5px;
    color: #374151;
}

.message-time {
    font-size: 11px;
    color: #6b7280;
    margin-top: 6px;
}

form {
    display: flex;
    gap: 10px;
}

input {
    flex: 1;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    outline: none;
}

input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
}

button {
    padding: 12px 20px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: white;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    font-weight: 600;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

button:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.2);
}
</style>

</head>

<body>


<div class="chat-container">

<div class="chat-header">
    <a class="back-link" href="view-community.php?id=<?php echo (int)$community_id; ?>">← Back</a>
    <h2>Community Chat</h2>
</div>

<div class="messages">

<?php if (!empty($messages)): ?>

    <?php foreach ($messages as $row): ?>

        <div class="message">

            <div class="username">

                <?php
                echo htmlspecialchars(
                    $row['username']
                );
                ?>

            </div>

            <div class="message-text">

                <?php
                echo htmlspecialchars(
                    $row['message']
                );
                ?>

            </div>

            <div class="message-time">

                <?php
                echo $row['created_at'];
                ?>

            </div>

        </div>

    <?php endforeach; ?>

<?php else: ?>

    <p>No messages yet. Start the conversation!</p>

<?php endif; ?>

</div>


<?php if (!$canChat): ?>
    <p>You need to join this community before sending messages.</p>
<?php elseif (!$messagesTableExists): ?>
    <p>Chat is not ready yet. Please create the community_messages table first.</p>
<?php else: ?>
<form id="chatForm">
    <input id="messageInput" type="text" name="message" placeholder="Type your message..." required>
    <button type="submit">Send</button>
</form>
<?php endif; ?>
</div>

<script>
const chatForm = document.getElementById('chatForm');
const messageInput = document.getElementById('messageInput');
const messagesBox = document.querySelector('.messages');
const communityId = <?php echo (int)$community_id; ?>;

function renderMessages(messages) {
    if (!messagesBox) return;
    messagesBox.innerHTML = '';

    if (!messages.length) {
        messagesBox.innerHTML = '<p>No messages yet. Start the conversation!</p>';
        return;
    }

    const html = messages.map(msg => `
        <div class="message">
            <div class="username">${msg.username}</div>
            <div class="message-text">${msg.message}</div>
            <div class="message-time">${msg.created_at}</div>
        </div>
    `).join('');

    messagesBox.innerHTML = html;
    messagesBox.scrollTop = messagesBox.scrollHeight;
}

async function loadMessages() {
    try {
        const response = await fetch('community-chat.php?id=' + communityId + '&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        renderMessages(data.messages || []);
    } catch (err) {
        console.error('Failed to load messages', err);
    }
}

if (chatForm && messageInput) {
    chatForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const message = messageInput.value.trim();
        if (!message) return;

        try {
            const response = await fetch('community-chat.php?id=' + communityId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'message=' + encodeURIComponent(message)
            });

            const result = await response.json();
            if (result.status === 'success') { 
                messageInput.value = '';
                await loadMessages();
            }
        } catch (err) {
            console.error('Failed to send message', err);
        }
    });
}

setInterval(loadMessages, 3000);
loadMessages();
</script>
</body>
</html>