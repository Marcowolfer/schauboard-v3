<?php

function schauboard_password_file(): string
{
    $config = schauboard_config();
    return $config['admin_password_file'];
}

function schauboard_load_password_hash(): ?string
{
    $path = schauboard_password_file();

    if (!is_file($path)) {
        return null;
    }

    $stored = require $path;
    if (!is_string($stored)) {
        return null;
    }

    $stored = trim($stored);
    return $stored !== '' ? $stored : null;
}

function schauboard_has_password(): bool
{
    return schauboard_load_password_hash() !== null;
}

function schauboard_store_password_hash(string $hash): bool
{
    $content = "<?php\n\nreturn " . var_export($hash, true) . ";\n";
    return schauboard_write_file(schauboard_password_file(), $content);
}

function schauboard_password_matches(string $input, ?string $storedHash = null): bool
{
    $storedHash ??= schauboard_load_password_hash();
    if (!is_string($storedHash) || $storedHash === '') {
        return false;
    }

    return password_verify($input, $storedHash);
}

function schauboard_session_is_authenticated(): bool
{
    return !empty($_SESSION['schauboard_admin_auth']);
}

function schauboard_require_admin_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!schauboard_session_is_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Nicht angemeldet.',
        ]);
        exit;
    }
}
