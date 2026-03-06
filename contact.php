<?php
/**
 * GreenClean Vietnam – contact.php
 * Xử lý form liên hệ, gửi mail qua Gmail SMTP bằng PHPMailer
 *
 * ===================== SETUP =====================
 * 1. Upload thư mục này lên hosting (cùng cấp index.html)
 * 2. Vào Gmail → Tài khoản Google → Bảo mật
 *    → Bật "Xác minh 2 bước" trước
 *    → Tìm "Mật khẩu ứng dụng" → Tạo mật khẩu cho "Mail"
 *    → Copy 16 ký tự (VD: abcd efgh ijkl mnop)
 * 3. Điền thông tin vào phần CẤU HÌNH bên dưới
 * =================================================
 */

// ==================== CẤU HÌNH ====================
// Đọc từ Environment Variables của Render (không hardcode credential)
// Vào Render Dashboard → Environment → thêm các biến bên dưới
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     getenv('SMTP_USER')     ?: 'tungkatu1901@gmail.com');
define('SMTP_PASS',     getenv('SMTP_PASS')     ?: '');
define('MAIL_TO',       getenv('MAIL_TO')       ?: 'tungkatu1901@gmail.com');
define('MAIL_TO_NAME',  getenv('MAIL_TO_NAME')  ?: 'CÔNG TY CỔ PHẦN KỸ THUẬT AAS');
define('ALLOWED_ORIGIN',getenv('ALLOWED_ORIGIN')?: 'https://aas-vn.netlify.app');
// ===================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// Chỉ chấp nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Đọc JSON body ──
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ── Rate-limit đơn giản theo IP (file-based) ──
$ip       = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash   = md5($ip);
$rateFile = sys_get_temp_dir() . '/gc_rl_' . $ipHash . '.txt';
$window   = 60; // giây
$maxReq   = 3;  // tối đa 3 request trong $window giây

$requests = [];
if (file_exists($rateFile)) {
    $requests = array_filter(
        json_decode(file_get_contents($rateFile), true) ?? [],
        fn($t) => time() - $t < $window
    );
}
if (count($requests) >= $maxReq) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Quá nhiều yêu cầu. Vui lòng thử lại sau ít phút.']);
    exit;
}
$requests[] = time();
file_put_contents($rateFile, json_encode(array_values($requests)));

// ── Lấy & làm sạch dữ liệu ──
$name    = trim(strip_tags($data['name']    ?? ''));
$phone   = preg_replace('/\s+/', '', strip_tags($data['phone'] ?? ''));
$email   = trim(strip_tags($data['email']   ?? ''));
$service = trim(strip_tags($data['service'] ?? ''));
$note    = trim(strip_tags($data['note']    ?? ''));
$website = trim($data['website'] ?? ''); // honeypot

// ── Honeypot ──
if ($website !== '') {
    echo json_encode(['ok' => true]); exit; // giả vờ thành công
}

// ── Validate ──
if (mb_strlen($name) < 2) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Họ tên không hợp lệ.']); exit;
}
if (!preg_match('/^(0|\+84)[0-9]{8,10}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Số điện thoại không hợp lệ.']); exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Email không hợp lệ.']); exit;
}

// ── Load PHPMailer qua Composer autoload ──
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Build & gửi mail ──
$time = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s');

$htmlBody = "
<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e0e8e4;border-radius:12px;overflow:hidden'>
  <div style='background:linear-gradient(135deg,#0f5c33,#1a8c4e);padding:28px 32px'>
    <h2 style='color:#fff;margin:0;font-size:20px'>🌿 GreenClean Vietnam</h2>
    <p style='color:rgba(255,255,255,.8);margin:6px 0 0;font-size:14px'>Yêu cầu tư vấn mới từ website</p>
  </div>
  <div style='padding:28px 32px;background:#fff'>
    <table style='width:100%;border-collapse:collapse;font-size:15px'>
      <tr><td style='padding:10px 0;color:#5a6a72;width:40%'>👤 Họ và tên</td><td style='padding:10px 0;font-weight:600;color:#1a2330'>" . htmlspecialchars($name) . "</td></tr>
      <tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>📞 Số điện thoại</td><td style='padding:10px 8px;font-weight:600;color:#1a2330'>" . htmlspecialchars($phone) . "</td></tr>
      " . ($email   ? "<tr><td style='padding:10px 0;color:#5a6a72'>📧 Email</td><td style='padding:10px 0;color:#1a2330'>" . htmlspecialchars($email) . "</td></tr>" : '') . "
      " . ($service ? "<tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>🔧 Dịch vụ</td><td style='padding:10px 8px;color:#1a2330'>" . htmlspecialchars($service) . "</td></tr>" : '') . "
      " . ($note    ? "<tr><td style='padding:10px 0;color:#5a6a72;vertical-align:top'>📝 Ghi chú</td><td style='padding:10px 0;color:#1a2330'>" . nl2br(htmlspecialchars($note)) . "</td></tr>" : '') . "
      <tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>⏰ Thời gian</td><td style='padding:10px 8px;color:#1a2330'>{$time}</td></tr>
    </table>
  </div>
  <div style='background:#e8f7ef;padding:16px 32px;text-align:center'>
    <p style='color:#0f5c33;font-size:13px;margin:0'>Email này được gửi tự động từ website GreenClean Vietnam</p>
  </div>
</div>
";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_USER, 'GreenClean Website');
    $mail->addAddress(MAIL_TO, MAIL_TO_NAME);
    if ($email) $mail->addReplyTo($email, $name); // reply thẳng về khách

    $mail->isHTML(true);
    $mail->Subject = '🌿 GreenClean – Yêu cầu tư vấn: ' . $name . ' – ' . $phone;
    $mail->Body    = $htmlBody;
    $mail->AltBody = "Họ tên: $name\nSĐT: $phone\nEmail: $email\nDịch vụ: $service\nGhi chú: $note\nThời gian: $time";

    $mail->send();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    http_response_code(502);
    error_log('[GreenClean] Mailer error: ' . $mail->ErrorInfo);
    echo json_encode(['ok' => false, 'error' => 'Gửi mail thất bại. Vui lòng thử lại.']);
}
