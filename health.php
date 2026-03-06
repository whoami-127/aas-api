<?php
// Render dùng endpoint này để kiểm tra service còn sống không
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'service' => 'AAS Contact API']);
