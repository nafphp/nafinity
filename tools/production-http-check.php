<?php

declare(strict_types=1);

// Only the disposable account created by bin/check-production-image.
$cookies = tempnam('/tmp', 'nafinity-http-');
if ($cookies === false) {
    throw new RuntimeException('Cannot create cookie jar.');
}

try {
    $request = static function (string $path, ?array $body = null) use ($cookies): array {
        $curl = curl_init('https://localhost:8443' . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CAINFO         => '/etc/nginx/ssl/fullchain.pem',
            CURLOPT_COOKIEFILE     => $cookies,
            CURLOPT_COOKIEJAR      => $cookies,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
        }
        $response = curl_exec($curl);
        if ($response === false) {
            throw new RuntimeException(curl_error($curl));
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        unset($curl);

        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    };

    [$status, $headers, $html] = $request('/login');
    if ($status !== 200 || !preg_match('/Set-Cookie:[^\r\n]*;\s*secure/i', $headers)
        || !preg_match('/Set-Cookie:[^\r\n]*;\s*HttpOnly/i', $headers)) {
        throw new RuntimeException('Login must return a secure HttpOnly session cookie.');
    }
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $token = (new DOMXPath($document))->evaluate('string(//input[@name="_csrf"]/@value)');
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Login has no CSRF token.');
    }
    $credentials = ['email' => 'probe@example.test', 'password' => 'Production probe password 42!'];
    [$status] = $request('/login', $credentials);
    if ($status !== 400) {
        throw new RuntimeException('Unexpected response to login without CSRF: ' . $status);
    }
    [$status, $headers] = $request('/login', $credentials + ['_csrf' => $token]);
    if ($status !== 303 || !preg_match('/^Location:\s*\/\s*$/mi', $headers)) {
        throw new RuntimeException('First administrator cannot sign in: ' . $status);
    }
    [$status] = $request('/settings/users');
    if ($status !== 200) {
        throw new RuntimeException('Administrator cannot manage users: ' . $status);
    }
    echo "Verified TLS, secure session cookies, CSRF rejection and first-administrator login.\n";
} finally {
    unlink($cookies);
}
