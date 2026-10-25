<?php
// Gambar CAPTCHA login (publik). Dibatasi laju agar tidak dipakai membebani server.
if (!RateLimit::hit('captcha_img', RateLimit::ip(), 60, 600)) { http_response_code(429); exit; }
if (($_GET['baru'] ?? '') === '1') { LoginGuard::siapkan(); }
LoginGuard::gambar();
exit;
