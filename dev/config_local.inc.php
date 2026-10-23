<?php
// Development config for the ExclusiveBBB dev stack. Synthetic data only.
define('Studip\\ENV', $_ENV['ENV'] ?? 'development');

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// all mail goes to the local Mailpit catcher, never to a real server
$MAIL_HOST_NAME = 'mailpit';
$MAIL_SMTP_OPTIONS = [
    'port' => 1025,
    'user' => '',
    'password' => '',
    'authentication_mechanism' => '',
    'ssl' => 0,
    'start_tls' => 0,
];
$MAIL_VALIDATE_HOST = false;
// CLI has no SERVER_NAME; without this the sender would be "wwwrun@"
$MAIL_LOCALHOST = 'studip.dev.example';

$CONTENT_LANGUAGES['en_GB'] = ['picture' => 'lang_en.gif', 'name' => 'English'];
