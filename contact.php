<?php
/**
 * AUTOROLNIK – contact.php
 * Obsługa formularza kontaktowego z zabezpieczeniami.
 * ZMIEŃ adres e-mail poniżej przed wdrożeniem!
 */

declare(strict_types=1);

/* ============================================================
   KONFIGURACJA – zmień przed wdrożeniem
   ============================================================ */
const RECIPIENT_EMAIL  = 'biuro@autorolnik.pl';   // <-- Twój e-mail docelowy
const SENDER_FROM      = 'formularz@autorolnik.pl'; // adres nadawcy (musi być na Twoim domenie)
const SITE_NAME        = 'autorolnik.pl';
const RATE_LIMIT_MAX   = 5;   // max zapytań z jednego IP na okno czasowe
const RATE_LIMIT_WINDOW = 600; // okno 10 minut (sekundy)
const SESSION_TIMEOUT  = 3600; // ważność CSRF (sekundy)

/* ============================================================
   BOOTSTRAP
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Tylko AJAX POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Metoda niedozwolona.']));
}

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Niedozwolone żądanie.']));
}

// Sesja dla CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

/* ============================================================
   RATE LIMITING (plik blokujący / opcjonalnie zastąp Redis)
   ============================================================ */
function checkRateLimit(): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'rl_' . md5($ip);
    $dir = sys_get_temp_dir() . '/autorolnik_rl/';

    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $file = $dir . $key;
    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($now - $data['start'] > RATE_LIMIT_WINDOW) {
            $data = ['count' => 0, 'start' => $now];
        }
    }

    if ($data['count'] >= RATE_LIMIT_MAX) {
        http_response_code(429);
        exit(json_encode(['success' => false, 'message' => 'Zbyt wiele zapytań. Zadzwoń do nas bezpośrednio.']));
    }

    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
}

checkRateLimit();

/* ============================================================
   CSRF WALIDACJA
   (Token generowany przez JS i przechowywany w sesji / wysyłany w formularzu)
   ============================================================ */
function validateCSRF(): void {
    $token = trim($_POST['csrf_token'] ?? '');

    // Inicjalizuj token sesji jeśli brak
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_time'])) {
        // Token sesji zaktualizowany przez JS – akceptuj token z pola
        if (empty($token) || strlen($token) < 32) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Błąd bezpieczeństwa. Odśwież stronę i spróbuj ponownie.']));
        }
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_time']  = time();
        return;
    }

    if (time() - $_SESSION['csrf_time'] > SESSION_TIMEOUT) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_time']);
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Sesja wygasła. Odśwież stronę.']));
    }

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Błąd weryfikacji bezpieczeństwa.']));
    }
}

validateCSRF();

/* ============================================================
   SANITYZACJA I WALIDACJA DANYCH
   ============================================================ */
function sanitizeString(string $input, int $maxLen = 255): string {
    return mb_substr(strip_tags(trim($input)), 0, $maxLen);
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone(string $phone): bool {
    return (bool) preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $phone);
}

$errors = [];

$name    = sanitizeString($_POST['name']    ?? '');
$phone   = sanitizeString($_POST['phone']   ?? '');
$email   = sanitizeString($_POST['email']   ?? '');
$area    = (int) ($_POST['area']           ?? 0);
$crops   = sanitizeString($_POST['crops']   ?? '');
$message = sanitizeString($_POST['message'] ?? '', 1000);

// Wymagane
if (mb_strlen($name) < 3) {
    $errors[] = 'Imię i Nazwisko musi mieć minimum 3 znaki.';
}
if (!validatePhone($phone)) {
    $errors[] = 'Nieprawidłowy numer telefonu.';
}
if (!validateEmail($email)) {
    $errors[] = 'Nieprawidłowy adres e-mail.';
}
if ($area < 1) {
    $errors[] = 'Podaj powierzchnię gospodarstwa.';
}

// Dozwolone wartości dla upraw
$allowedCrops = ['maliny', 'borowki', 'truskawki', 'zboza', 'rzepak', 'warzywa', 'inne_owoce', 'mix'];
if (!in_array($crops, $allowedCrops, true)) {
    $errors[] = 'Wybierz rodzaj upraw z listy.';
}

// Honeypot – pole ukryte (dodaj do HTML jeśli chcesz dodatkową warstwę)
if (!empty($_POST['website'])) {
    // Cichy sukces dla botów
    exit(json_encode(['success' => true]));
}

if (!empty($errors)) {
    http_response_code(422);
    exit(json_encode(['success' => false, 'message' => implode(' ', $errors)]));
}

/* ============================================================
   ETYKIETY UPRAW
   ============================================================ */
$cropsLabels = [
    'maliny'      => 'Maliny',
    'borowki'     => 'Borówki',
    'truskawki'   => 'Truskawki',
    'zboza'       => 'Zboża',
    'rzepak'      => 'Rzepak',
    'warzywa'     => 'Warzywa',
    'inne_owoce'  => 'Inne owoce',
    'mix'         => 'Mieszane uprawy',
];
$cropsLabel = $cropsLabels[$crops] ?? $crops;

/* ============================================================
   BUDOWANIE TREŚCI E-MAILA
   ============================================================ */
$date    = date('d.m.Y H:i:s');
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'nieznany';
$subject = '=?UTF-8?B?' . base64_encode("AUTOROLNIK – nowe zapytanie ofertowe: {$name}") . '?=';

$body = <<<EOT
=== NOWE ZAPYTANIE OFERTOWE – AUTOROLNIK ===

Data: {$date}
Adres IP: {$ip}

--- DANE KONTAKTOWE ---
Imię i Nazwisko : {$name}
Telefon         : {$phone}
E-mail          : {$email}

--- GOSPODARSTWO ---
Powierzchnia    : {$area} ha
Rodzaj upraw    : {$cropsLabel}

--- WIADOMOŚĆ ---
{$message}

============================================
Wiadomość wysłana z formularza na {$site}
============================================
EOT;

$body = str_replace('{$site}', SITE_NAME, $body);

$headers  = "From: " . SITE_NAME . " <" . SENDER_FROM . ">\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: base64\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "X-Priority: 1 (Highest)\r\n";

$bodyEncoded = base64_encode($body);

/* ============================================================
   WYSYŁKA
   ============================================================ */
$sent = mail(RECIPIENT_EMAIL, $subject, $bodyEncoded, $headers);

if ($sent) {
    // Odnów CSRF token po wysyłce
    unset($_SESSION['csrf_token'], $_SESSION['csrf_time']);

    // Loguj zapytanie (opcjonalnie – wykomentuj jeśli nie potrzebujesz)
    $logDir  = __DIR__ . '/logs/';
    $logFile = $logDir . 'leads_' . date('Y-m') . '.log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0700, true);
    }
    $logEntry = "[{$date}] IP:{$ip} | {$name} | {$phone} | {$email} | {$area}ha | {$cropsLabel}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

    exit(json_encode(['success' => true]));
} else {
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Błąd serwera podczas wysyłki. Zadzwoń do nas bezpośrednio.'
    ]));
}
