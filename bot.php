<?php
require_once __DIR__ . '/functions.php';

$config = loadConfig();
$db = initDb();

$update = json_decode(file_get_contents('php://input'), true);
if (!$update || !isset($update['message'])) {
    http_response_code(200);
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'] ?? 0;
$fromId = $message['from']['id'] ?? 0;
$username = $message['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? null;
$text = trim($message['text'] ?? '');
$caption = trim($message['caption'] ?? '');

// ========== أوامر الأدمن ==========
if (isAdmin($fromId) && $text !== '') {
    if ($text === '/start') {
        tgRequest('sendMessage', ['chat_id' => $chatId, 'text' =>
            "مرحبًا أيها الأدمن 👑\n\n" .
            "الأوامر المتاحة:\n" .
            "/pending - عرض الأسئلة المعلقة\n" .
            "/stats - إحصائيات\n" .
            "/answer <رقم> <الإجابة> - الرد على سؤال معلق وإضافته\n" .
            "/broadcast <نص> - إرسال نص لكل الطلاب\n" .
            "أو أرسل صورة/فيديو مع caption /broadcast لبثها للجميع"
        ]);
        exit;
    }

    if ($text === '/stats') {
        $users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $pending = $db->query("SELECT COUNT(*) FROM unanswered WHERE status='pending'")->fetchColumn();
        $faqCount = $db->query("SELECT COUNT(*) FROM faq")->fetchColumn();
        tgRequest('sendMessage', ['chat_id' => $chatId, 'text' =>
            "📊 إحصائيات:\n- المستخدمون: {$users}\n- الأسئلة في FAQ: {$faqCount}\n- الأسئلة المعلقة: {$pending}"
        ]);
        exit;
    }

    if ($text === '/pending') {
        $rows = $db->query("SELECT id, username, question FROM unanswered WHERE status='pending' ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "لا توجد أسئلة معلقة."]);
        } else {
            $out = "📋 الأسئلة المعلقة:\n\n";
            foreach ($rows as $r) {
                $u = $r['username'] ? "@{$r['username']}" : "مجهول";
                $out .= "#{$r['id']} - {$u}\n   {$r['question']}\n\n";
            }
            $out .= "للرد: /answer <رقم> <الإجابة>";
            tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => $out]);
        }
        exit;
    }

    if (preg_match('/^\/answer\s+(\d+)\s+(.+)$/s', $text, $m)) {
        $qid = (int)$m[1];
        $answerText = trim($m[2]);
        $stmt = $db->prepare("SELECT question FROM unanswered WHERE id = ?");
        $stmt->execute([$qid]);
        $q = $stmt->fetchColumn();
        if (!$q) {
            tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "السؤال رقم {$qid} غير موجود."]);
            exit;
        }
        $emb = getEmbedding($q . "\n" . $answerText);
        if (!$emb) {
            tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "فشل توليد التضمين."]);
            exit;
        }
        $db->prepare("INSERT INTO faq (question, answer, embedding) VALUES (?, ?, ?)")
           ->execute([$q, $answerText, json_encode($emb)]);
        $db->prepare("UPDATE unanswered SET status='resolved' WHERE id = ?")->execute([$qid]);
        tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ تمت الإضافة للـ FAQ."]);
        exit;
    }

    if (preg_match('/^\/broadcast\s+(.+)$/s', $text, $m)) {
        $bt = trim($m[1]);
        $users = $db->query("SELECT telegram_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($users as $uid) {
            if (tgRequest('sendMessage', ['chat_id' => $uid, 'text' => $bt])) $count++;
            usleep(50000);
        }
        tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "تم الإرسال إلى {$count} مستخدم."]);
        exit;
    }
}

// ========== بث وسائط للأدمن ==========
if (isAdmin($fromId) && $caption === '/broadcast') {
    $fileId = null; $method = null; $param = null;
    if (isset($message['photo'])) {
        $photos = $message['photo'];
        $fileId = end($photos)['file_id'];
        $method = 'sendPhoto'; $param = 'photo';
    } elseif (isset($message['video'])) {
        $fileId = $message['video']['file_id'];
        $method = 'sendVideo'; $param = 'video';
    } elseif (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
        $method = 'sendDocument'; $param = 'document';
    }
    if ($fileId) {
        $users = $db->query("SELECT telegram_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($users as $uid) {
            if (tgRequest($method, ['chat_id' => $uid, $param => $fileId])) $count++;
            usleep(50000);
        }
        tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => "تم إرسال الوسائط إلى {$count} مستخدم."]);
    }
    exit;
}

// ========== استقبال رسائل الطلاب ==========
if ($text === '' || str_starts_with($text, '/')) exit;

trackUser($db, $fromId, $username, $firstName);

$match = findBestAnswer($text, $db);
if ($match) {
    $reply = $match['answer'];
    $faqId = $match['id'];
} else {
    $reply = "عذرًا، لم أجد إجابة موثوقة لسؤالك في قاعدة البيانات الحالية. تم رفع استفسارك إلى إدارة شؤون الطلاب وسيتم الرد عليك قريبًا إن شاء الله.";
    $faqId = null;
    $db->prepare("INSERT INTO unanswered (telegram_id, username, question) VALUES (?, ?, ?)")
       ->execute([$fromId, $username, $text]);
}

tgRequest('sendMessage', ['chat_id' => $chatId, 'text' => $reply]);
logMessage($db, $fromId, $username, $text, $reply, $faqId);

http_response_code(200);