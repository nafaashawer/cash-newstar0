<?php
// ==============================================================
// receive_sms.php
// النقطة اللي التطبيق على الموبايل (MacroDroid/Tasker) هيبعت لها
// كل رسالة كاش توصل على الشريحة
// ==============================================================

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config_sms.php';

function respond($status, $message, $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) نسمح بطلبات POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond('error', 'Method not allowed');
}

// 2) قراءة البيانات (بتقبل form-data عادي أو JSON، عشان MacroDroid و Tasker مختلفين شوية)
$input = $_POST;
if (empty($input)) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) $input = $json;
}

$apiKey  = $input['api_key']  ?? '';
$service = strtolower(trim($input['service'] ?? ''));   // vodafone / etisalat / orange
$sender  = trim($input['sender']  ?? '');                // الرقم/الكود اللي وصلت منه الرسالة
$message = trim($input['message'] ?? '');                // نص الرسالة كامل

// 3) التحقق من المفتاح السري
if (!hash_equals(SMS_API_KEY, $apiKey)) {
    http_response_code(401);
    respond('error', 'Invalid API key');
}

if ($message === '') {
    respond('error', 'Missing message text');
}

$conn = get_db_connection();

// 4) استخراج رقم هاتف العميل من نص الرسالة (بيكون مذكور جوه نص رسالة الكاش)
$phoneExtracted = null;
if (preg_match('/01[0125]\d{8}/', $message, $m)) {
    $phoneExtracted = $m[0];
}

// 5) استخراج المبلغ من نص الرسالة
// بيدور على رقم قبل كلمة جنيه/EGP وبيشيل الفواصل
$amount = null;
if (preg_match('/([\d]{1,3}(?:[,.\d]{0,10}))\s*(?:جنيه|EGP|LE|ج\.م)/u', $message, $m)) {
    $rawAmount = str_replace(',', '', $m[1]);
    // لو فيه فاصلة عشرية زيادة عن اللازم بسبب فواصل الآلاف نضبطها
    $amount = (float) $rawAmount;
}

// 6) بصمة فريدة للرسالة عشان نمنع تسجيلها مرتين (لو MacroDroid بعتها بالغلط مرتين مثلاً)
$dedupeHash = hash('sha256', $service . '|' . $sender . '|' . $message);

// نتأكد إن الرسالة دي ما اتسجلتش قبل كده
$check = $conn->prepare("SELECT id FROM cash_transactions WHERE dedupe_hash = ?");
$check->execute([$dedupeHash]);
if ($check->fetch()) {
    respond('ok', 'Duplicate message ignored (already processed)');
}

// 7) لو فشلنا نستخرج المبلغ أو الرقم، نسجلها كخطأ تحليل عشان تراجعها يدوي
if ($amount === null || $phoneExtracted === null) {
    $stmt = $conn->prepare("INSERT INTO cash_transactions
        (service, sender_raw, phone_extracted, amount, raw_message, dedupe_hash, distributor_id, status)
        VALUES (?, ?, ?, ?, ?, ?, NULL, 'parse_error')");
    $stmt->execute([$service, $sender, $phoneExtracted, $amount, $message, $dedupeHash]);
    respond('warning', 'Could not parse amount/phone from message, logged for manual review');
}

// 8) البحث عن الموزع صاحب الرقم ده (بنقارن آخر 10 أرقام عشان نتفادى مشاكل +20 أو الصفر في الأول)
$conn->beginTransaction();
try {
    $stmt = $conn->prepare("
        SELECT id, dis_balance, auto_topup FROM distributor
        WHERE RIGHT(REPLACE(dis_phone, ' ', ''), 10) = RIGHT(?, 10)
        FOR UPDATE
    ");
    $stmt->execute([$phoneExtracted]);
    $distributor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$distributor) {
        $conn->commit();
        $stmt = $conn->prepare("INSERT INTO cash_transactions
            (service, sender_raw, phone_extracted, amount, raw_message, dedupe_hash, distributor_id, status)
            VALUES (?, ?, ?, ?, ?, ?, NULL, 'unmatched')");
        $stmt->execute([$service, $sender, $phoneExtracted, $amount, $message, $dedupeHash]);
        respond('warning', 'No distributor found with this phone number', ['phone' => $phoneExtracted, 'amount' => $amount]);
    }

    if ($distributor['auto_topup'] === 'off') {
        $conn->commit();
        respond('warning', 'Distributor has auto top-up disabled', ['distributor_id' => $distributor['id']]);
    }

    // إضافة الرصيد فعلياً
    $update = $conn->prepare("UPDATE distributor SET dis_balance = dis_balance + ? WHERE id = ?");
    $update->execute([$amount, $distributor['id']]);

    $log = $conn->prepare("INSERT INTO cash_transactions
        (service, sender_raw, phone_extracted, amount, raw_message, dedupe_hash, distributor_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'matched')");
    $log->execute([$service, $sender, $phoneExtracted, $amount, $message, $dedupeHash, $distributor['id']]);

    $conn->commit();

    respond('ok', 'Balance updated successfully', [
        'distributor_id' => $distributor['id'],
        'amount_added'   => $amount,
        'new_balance'    => $distributor['dis_balance'] + $amount,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    respond('error', 'Server error while updating balance');
}
