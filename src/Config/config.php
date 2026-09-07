<?php
return [
'base_url' => getenv('REMITA_BASE_URL'),
'secret_key' => getenv('REMITA_SECRET_KEY'),
'callback_url' => getenv('APP_URL') . '/public/callback.php',
];