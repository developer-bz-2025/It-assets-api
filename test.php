<?php
// $secretKey = bin2hex(random_bytes(32)); // Generates a 64-character hexadecimal string
// echo $secretKey;

echo password_hash('alaa123', PASSWORD_DEFAULT);
