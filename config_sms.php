<?php
// ==============================================================
// ملف الإعدادات - لا تشاركه مع حد ولا ترفعه على أي مكان عام
// ==============================================================

// نفس بيانات الاتصال بقاعدة البيانات الموجودة عندك بالفعل
$servername = "localhost";
$userdb     = "u834067377_cards";
$passdb     = "Aa01555334056575@#";
$dbname     = "u834067377_cards";

// مفتاح سري لازم يتبعت مع كل طلب من التطبيق على الموبايل
// غيّره لأي قيمة عشوائية طويلة أنت تختارها (مش لازم تكون زي المثال)
define('SMS_API_KEY', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_1234567890');

function get_db_connection() {
    global $servername, $userdb, $passdb, $dbname;
    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $userdb, $passdb);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['status' => 'error', 'message' => 'DB connection failed'], JSON_UNESCAPED_UNICODE));
    }
}
