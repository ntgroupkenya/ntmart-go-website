<?php
/*
 * Contact form handler for contact.html (posted by assets/js/main.js).
 * Set $emailTo to the inbox that should receive messages, and $emailFrom to an
 * address on your own domain (many hosts reject mail "from" other domains).
 * Needs PHP 7.4+ and a server where mail() is configured.
 */

declare(strict_types=1);

$emailTo   = 'ntmart@ntgroup.co.ke';
$emailFrom = 'no-reply@ntgroup.co.ke';
$siteName  = 'NTmart Go';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, bool $ok, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

function field(string $key, int $max): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

// Strip line breaks so user input can't add mail headers.
function oneLine(string $value): string
{
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $value));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

// Honeypot: real visitors never see or fill this field.
if (field('website', 100) !== '') {
    respond(200, true, 'Thanks! Your message has been sent.');
}

$name     = oneLine(field('name', 100));
$email    = oneLine(field('email', 150));
$phone    = oneLine(field('phone', 30));
$business = oneLine(field('business', 150));
$topic    = oneLine(field('topic', 40));
$message  = field('message', 5000);
$consent  = isset($_POST['consent']);

$topics = [
    'general' => 'General question',
    'purchase' => 'Buy NTmart Go',
    'support' => 'Support',
];

$errors = [];
if ($name === '') {
    $errors[] = 'your name';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'a valid email address';
}
if ($phone !== '' && !preg_match('/^[0-9+()\s-]{7,20}$/', $phone)) {
    $errors[] = 'a valid phone number';
}
if (strlen($message) < 10) {
    $errors[] = 'a message of at least 10 characters';
}
if (!$consent) {
    $errors[] = 'your agreement to the privacy policy';
}
if ($errors) {
    respond(422, false, 'Please provide ' . implode(', ', $errors) . '.');
}

$topicLabel = $topics[$topic] ?? $topics['general'];
$subject = sprintf('[%s] %s from %s', $siteName, $topicLabel, $name);

$body = "Name: $name\n"
      . "Email: $email\n"
      . 'Phone: ' . ($phone !== '' ? $phone : '-') . "\n"
      . 'Business: ' . ($business !== '' ? $business : '-') . "\n"
      . "Topic: $topicLabel\n\n"
      . $message . "\n";

$headers = [
    'From: ' . $siteName . ' website <' . $emailFrom . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail($emailTo, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

if (!$sent) {
    respond(500, false, 'Sorry, your message could not be sent. Please email ntmart@ntgroup.co.ke or WhatsApp +254 711 294 124.');
}

respond(200, true, "Thanks, $name! Your message has been sent. We'll get back to you soon.");
