<?php
/**
 * CÔNG TY CỔ PHẦN KỸ THUẬT AAS – contact.php
 * Gửi mail qua SendGrid API (HTTPS) – không dùng SMTP
 * Render free tier không chặn HTTPS outbound
 *
 * SETUP:
 * 1. Đăng ký miễn phí tại sendgrid.com
 * 2. Vào Settings → API Keys → Create API Key → Full Access
 * 3. Thêm vào Render Environment Variables:
 *    SENDGRID_API_KEY = SG.xxxxxxxxxxxxxxxx
 *    MAIL_TO          = email-nhan@cty.vn
 *    MAIL_FROM        = email-gui@gmail.com  (phải verify trên SendGrid)
 *    ALLOWED_ORIGIN   = https://aas-vn.netlify.app
 */

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowed = getenv('ALLOWED_ORIGIN') ?: '*';
$corsOrigin = ($allowed === '*') ? '*' : $origin;

header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

// ── Rate limit theo IP ──
$ip       = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/gc_rl_' . md5($ip) . '.txt';
$window   = 60; $maxReq = 3;
$requests = [];
if (file_exists($rateFile)) {
    $requests = array_filter(
        json_decode(file_get_contents($rateFile), true) ?? [],
        fn($t) => time() - $t < $window
    );
}
if (count($requests) >= $maxReq) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Quá nhiều yêu cầu. Thử lại sau ít phút.']); exit;
}
$requests[] = time();
file_put_contents($rateFile, json_encode(array_values($requests)));

// ── Parse body ──
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit;
}

$name    = trim(strip_tags($data['name']    ?? ''));
$phone   = preg_replace('/\s+/', '', strip_tags($data['phone'] ?? ''));
$email   = trim(strip_tags($data['email']   ?? ''));
$service = trim(strip_tags($data['service'] ?? ''));
$note    = trim(strip_tags($data['note']    ?? ''));
$website = trim($data['website'] ?? '');

// ── Honeypot ──
if ($website !== '') { echo json_encode(['ok' => true]); exit; }

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

// ── Config từ Render Environment Variables ──
$sgApiKey  = getenv('SENDGRID_API_KEY') ?: '';
$mailTo    = getenv('MAIL_TO')          ?: '';
$mailFrom  = getenv('MAIL_FROM')        ?: $mailTo;
$mailName  = getenv('MAIL_TO_NAME')     ?: 'CÔNG TY CỔ PHẦN KỸ THUẬT AAS';
$time      = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s');

if (!$sgApiKey || !$mailTo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server chưa cấu hình email.']); exit;
}

// ── Build HTML email ──
$rows = "
  <tr><td style='padding:10px 0;color:#5a6a72;width:38%'>👤 Họ và tên</td><td style='padding:10px 0;font-weight:600;color:#1a2330'>" . htmlspecialchars($name) . "</td></tr>
  <tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>📞 Số điện thoại</td><td style='padding:10px 8px;font-weight:700;color:#1a2330'>" . htmlspecialchars($phone) . "</td></tr>";
if ($email)   $rows .= "<tr><td style='padding:10px 0;color:#5a6a72'>📧 Email</td><td style='padding:10px 0;color:#1a2330'>" . htmlspecialchars($email) . "</td></tr>";
if ($service) $rows .= "<tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>🔧 Dịch vụ</td><td style='padding:10px 8px;color:#1a2330'>" . htmlspecialchars($service) . "</td></tr>";
if ($note)    $rows .= "<tr><td style='padding:10px 0;color:#5a6a72;vertical-align:top'>📝 Ghi chú</td><td style='padding:10px 0;color:#1a2330'>" . nl2br(htmlspecialchars($note)) . "</td></tr>";
$rows .= "<tr style='background:#f7faf8'><td style='padding:10px 8px;color:#5a6a72'>⏰ Thời gian</td><td style='padding:10px 8px;color:#1a2330'>{$time}</td></tr>";

$htmlBody = "
<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e0e8e4;border-radius:12px;overflow:hidden'>
  <div style='background:#0f5c33;padding:28px 32px'>
    <h2 style='color:#fff;margin:0;font-size:20px'>🌿 CÔNG TY CỔ PHẦN KỸ THUẬT AAS</h2>
    <p style='color:rgba(255,255,255,.8);margin:6px 0 0;font-size:14px'>Yêu cầu tư vấn mới từ website</p>
  </div>
  <div style='padding:28px 32px;background:#fff'>
    <table style='width:100%;border-collapse:collapse;font-size:15px'>{$rows}</table>
  </div>
  <div style='background:#e8f7ef;padding:16px 32px;text-align:center'>
    <p style='color:#0f5c33;font-size:13px;margin:0'>Email tự động từ website CÔNG TY CỔ PHẦN KỸ THUẬT AAS</p>
  </div>
</div>";

$textBody = "Họ tên: $name\nSĐT: $phone\nEmail: $email\nDịch vụ: $service\nGhi chú: $note\nThời gian: $time";

// ── Gửi qua SendGrid API (HTTPS – Render không chặn) ──
$payload = [
    'personalizations' => [[
        'to' => [['email' => $mailTo, 'name' => $mailName]],
        'subject' => "🌿 Yêu cầu tư vấn: {$name} – {$phone}",
    ]],
    'from'    => ['email' => $mailFrom, 'name' => 'AAS Website'],
    'content' => [
        ['type' => 'text/plain', 'value' => $textBody],
        ['type' => 'text/html',  'value' => $htmlBody],
    ],
];

// Nếu khách có email → reply-to về khách
if ($email) {
    $payload['reply_to'] = ['email' => $email, 'name' => $name];
}

$ch = curl_init('https://api.sendgrid.com/v3/mail/send');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $sgApiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("[CÔNG TY CỔ PHẦN KỸ THUẬT AAS] cURL error: $curlErr");
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Không thể kết nối đến mail server.']); exit;
}

// SendGrid trả về 202 = thành công
if ($httpCode === 202) {
    echo json_encode(['ok' => true]); exit;
}

error_log("[CÔNG TY CỔ PHẦN KỸ THUẬT AAS] SendGrid error $httpCode: $response");
http_response_code(502);
echo json_encode(['ok' => false, 'error' => 'Gửi mail thất bại. Vui lòng thử lại.']);
