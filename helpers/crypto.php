<?php
function ai_encrypt($data) {
    if ($data === null) return null;
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        return null;
    }
    return 'gcm:' . base64_encode($iv . $tag . $ciphertext);
}

function ai_decrypt($data) {
    if ($data === null) return null;
    if (strpos($data, 'gcm:') === 0) {
        $raw = base64_decode(substr($data, 4), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $key = hash('sha256', ENCRYPTION_KEY, true);
        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }

    // Legacy CBC fallback for previously stored values.
    $key = ENCRYPTION_KEY;
    $iv = substr(hash('sha256', $key), 0, 16);
    return openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv);
}
