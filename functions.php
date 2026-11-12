<?php

function loadConfig(): array {
    static $config = null;
    if ($config === null) $config = require __DIR__ . '/config.php';
    return $config;
}

function initDb(): PDO {
    $config = loadConfig();
    $dsn = "mysql:host={$config['mysql_host']};dbname={$config['mysql_dbname']};charset=utf8mb4";
    $db = new PDO($dsn, $config['mysql_username'], $config['mysql_password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // إنشاء الجداول إن لم تكن موجودة
    $db->exec("
        CREATE TABLE IF NOT EXISTS faq (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            embedding LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS unanswered (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NOT NULL,
            username VARCHAR(100),
            question TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS users (
            telegram_id BIGINT PRIMARY KEY,
            username VARCHAR(100),
            first_name VARCHAR(100),
            question_count INT DEFAULT 0,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS message_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NOT NULL,
            username VARCHAR(100),
            incoming_message TEXT,
            bot_reply TEXT,
            matched_faq_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    return $db;
}

function tgRequest(string $method, array $params = []): ?array {
    $config = loadConfig();
    $url = "https://api.telegram.org/bot{$config['telegram_token']}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}

function getEmbedding(string $text): ?array {
    $config = loadConfig();
    $model = $config['embedding_model'];
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$config['gemini_api_key']}";
    $payload = json_encode([
        'model' => "models/{$model}",
        'content' => ['parts' => [['text' => $text]]]
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['embedding']['values'] ?? null;
}

function cosineSimilarity(array $a, array $b): float {
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na += $a[$i] * $a[$i];
        $nb += $b[$i] * $b[$i];
    }
    if ($na == 0 || $nb == 0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

function findBestAnswer(string $userText, PDO $db): ?array {
    $config = loadConfig();
    $queryEmb = getEmbedding($userText);
    if (!$queryEmb) return null;

    $stmt = $db->query("SELECT id, question, answer, embedding FROM faq WHERE embedding IS NOT NULL");
    $best = null; $bestScore = -1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $emb = json_decode($row['embedding'], true);
        if (!is_array($emb)) continue;
        $score = cosineSimilarity($queryEmb, $emb);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }
    if ($best && $bestScore >= $config['similarity_threshold']) {
        $best['score'] = $bestScore;
        return $best;
    }
    return null;
}

function isAdmin(int $id): bool {
    $config = loadConfig();
    return in_array($id, $config['admin_telegram_ids'], true);
}

function trackUser(PDO $db, int $id, ?string $username, ?string $firstName): void {
    $stmt = $db->prepare("
        INSERT INTO users (telegram_id, username, first_name, question_count)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            question_count = question_count + 1,
            last_seen = CURRENT_TIMESTAMP,
            username = VALUES(username),
            first_name = VALUES(first_name)
    ");
    $stmt->execute([$id, $username, $firstName]);
}

function logMessage(PDO $db, int $id, ?string $username, string $incoming, string $reply, ?int $faqId): void {
    $stmt = $db->prepare("
        INSERT INTO message_log (telegram_id, username, incoming_message, bot_reply, matched_faq_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$id, $username, $incoming, $reply, $faqId]);
}