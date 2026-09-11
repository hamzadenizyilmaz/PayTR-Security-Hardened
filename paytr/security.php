<?php

if (!function_exists('paytrResolveRemoteAddress')) {
    function paytrResolveRemoteAddress(array $server)
    {
        $remoteAddress = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddress)) {
            return null;
        }

        $remoteAddress = trim($remoteAddress);

        return filter_var($remoteAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $remoteAddress;
    }
}

if (!function_exists('paytrReadCallbackPayload')) {
    function paytrReadCallbackPayload(array $payload)
    {
        $requiredFields = array(
            'hash',
            'merchant_oid',
            'status',
            'total_amount',
            'payment_amount',
            'currency',
        );

        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                return null;
            }
        }

        $decodedHash = strlen($payload['hash']) === 44
            ? base64_decode($payload['hash'], true)
            : false;
        if ($decodedHash === false
            || strlen($decodedHash) !== 32
            || !hash_equals(base64_encode($decodedHash), $payload['hash'])
        ) {
            return null;
        }

        if (!preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $payload['merchant_oid'])
            || !in_array($payload['status'], array('success', 'failed'), true)
            || !preg_match('/\A\d{1,18}\z/D', $payload['total_amount'])
            || !preg_match('/\A\d{1,18}\z/D', $payload['payment_amount'])
            || !in_array($payload['currency'], array('TL', 'USD', 'EUR', 'GBP', 'RUB'), true)
        ) {
            return null;
        }

        $testMode = $payload['test_mode'] ?? '0';
        if (!is_string($testMode) || !in_array($testMode, array('0', '1'), true)) {
            return null;
        }

        return array(
            'hash' => $payload['hash'],
            'merchant_oid' => $payload['merchant_oid'],
            'status' => $payload['status'],
            'total_amount' => $payload['total_amount'],
            'payment_amount' => $payload['payment_amount'],
            'currency' => $payload['currency'],
            'test_mode' => $testMode === '1',
        );
    }
}

if (!function_exists('paytrCallbackFailureLogPayload')) {
    function paytrCallbackFailureLogPayload($isAuthenticated, array $payload)
    {
        return $isAuthenticated ? $payload : null;
    }
}

if (!function_exists('paytrBuildInvoiceNote')) {
    function paytrBuildInvoiceNote($existingNotes, $message)
    {
        $existingNotes = rtrim((string)$existingNotes);
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$message);
        $message = trim((string)$message);
        $message = substr($message, 0, 2000);

        if ($message === '') {
            return $existingNotes;
        }

        return $existingNotes === ''
            ? $message
            : $existingNotes . "\n" . $message;
    }
}

if (!function_exists('paytrVerifyCallbackHash')) {
    function paytrVerifyCallbackHash(
        $merchantOid,
        $status,
        $totalAmount,
        $merchantSalt,
        $merchantKey,
        $providedHash
    ) {
        if (!is_string($providedHash)) {
            return false;
        }

        $hashString = (string)$merchantOid
            . (string)$merchantSalt
            . (string)$status
            . (string)$totalAmount;
        $expectedHash = base64_encode(hash_hmac(
            'sha256',
            $hashString,
            (string)$merchantKey,
            true
        ));

        return hash_equals($expectedHash, $providedHash);
    }
}

if (!function_exists('paytrHttpTimeouts')) {
    function paytrHttpTimeouts($operation)
    {
        if ($operation === 'refund') {
            return array('timeout' => 30, 'connect_timeout' => 5);
        }

        return array('timeout' => 8, 'connect_timeout' => 3);
    }
}

if (!function_exists('paytrParseMerchantOid')) {
    function paytrParseMerchantOid($merchantOid)
    {
        if (!is_string($merchantOid)) {
            return null;
        }

        $pattern = '/\ASP(\d+)WHMCS(\d+)M([01])A(\d+)C(TL|USD|EUR|GBP|RUB)R([a-f0-9]{12})\z/Di';
        if (!preg_match($pattern, $merchantOid, $matches)) {
            return null;
        }

        return array(
            'invoice_id' => (int)$matches[1],
            'created_at' => (int)$matches[2],
            'test_mode' => $matches[3] === '1',
            'amount_minor' => (int)$matches[4],
            'currency' => strtoupper($matches[5]),
        );
    }
}

if (!function_exists('paytrDecimalToMinor')) {
    function paytrDecimalToMinor($amount)
    {
        if (!is_scalar($amount)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string)$amount));
        if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $normalized)) {
            return null;
        }

        $parts = explode('.', $normalized, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        if (strlen($whole) > 16) {
            return null;
        }

        return ((int)$whole * 100) + (int)$fraction;
    }
}

if (!function_exists('paytrInvoiceAmountMatches')) {
    function paytrInvoiceAmountMatches($invoiceTotal, $paymentMinor)
    {
        $invoiceMinor = paytrDecimalToMinor($invoiceTotal);

        return $invoiceMinor !== null
            && is_int($paymentMinor)
            && $invoiceMinor === $paymentMinor;
    }
}

if (!function_exists('paytrParsePaymentStatusResponse')) {
    function paytrParsePaymentStatusResponse(array $result)
    {
        $requiredScalarFields = array(
            'status',
            'payment_amount',
            'payment_total',
            'currency',
            'test_mode',
            'taksit',
        );
        foreach ($requiredScalarFields as $field) {
            if (!isset($result[$field]) || !is_scalar($result[$field])) {
                return null;
            }
        }

        if ((string)$result['status'] !== 'success') {
            return null;
        }

        $paymentMinor = paytrDecimalToMinor($result['payment_amount']);
        $totalMinor = paytrDecimalToMinor($result['payment_total']);
        $currency = strtoupper(trim((string)$result['currency']));
        $currency = $currency === 'TRY' ? 'TL' : $currency;
        $testMode = (string)$result['test_mode'];
        $installment = (string)$result['taksit'];
        $allowedInstallments = array('0', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12');

        if ($paymentMinor === null
            || $totalMinor === null
            || !in_array($currency, array('TL', 'USD', 'EUR', 'GBP', 'RUB'), true)
            || !in_array($testMode, array('0', '1'), true)
            || !in_array($installment, $allowedInstallments, true)
        ) {
            return null;
        }

        return array(
            'payment_minor' => $paymentMinor,
            'total_minor' => $totalMinor,
            'currency' => $currency,
            'test_mode' => $testMode === '1',
            'installment_count' => max(1, (int)$installment),
        );
    }
}

if (!function_exists('paytrStorePaymentSession')) {
    function paytrStorePaymentSession(array &$session, $invoiceId, $token, $createdAt = null)
    {
        $invoiceId = (int)$invoiceId;
        $token = is_string($token) ? $token : '';
        $createdAt = $createdAt === null ? time() : (int)$createdAt;

        if ($invoiceId <= 0
            || !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token)
            || $createdAt <= 0
        ) {
            return null;
        }

        $nonce = bin2hex(random_bytes(32));
        $session['paytr_iframe_token_' . $invoiceId] = array(
            'token' => $token,
            'invoice_id' => $invoiceId,
            'created_at' => $createdAt,
            'nonce_hash' => hash('sha256', $nonce),
        );

        $paymentSessions = array();
        foreach ($session as $key => $value) {
            if (strpos((string)$key, 'paytr_iframe_token_') !== 0 || !is_array($value)) {
                continue;
            }

            $storedAt = (int)($value['created_at'] ?? 0);
            if ($storedAt < $createdAt - 1800 || $storedAt > $createdAt + 60) {
                unset($session[$key]);
                continue;
            }

            $paymentSessions[$key] = $storedAt;
        }

        asort($paymentSessions, SORT_NUMERIC);
        while (count($paymentSessions) > 8) {
            $oldestKey = array_key_first($paymentSessions);
            unset($paymentSessions[$oldestKey], $session[$oldestKey]);
        }

        return $nonce;
    }
}

if (!function_exists('paytrConsumePaymentSession')) {
    function paytrConsumePaymentSession(array &$session, $invoiceId, $nonce, $now = null)
    {
        $invoiceId = (int)$invoiceId;
        $nonce = is_string($nonce) ? $nonce : '';
        $now = $now === null ? time() : (int)$now;
        $sessionKey = 'paytr_iframe_token_' . $invoiceId;
        $payment = $session[$sessionKey] ?? null;

        if (!is_array($payment)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $nonce)
            || (int)($payment['invoice_id'] ?? 0) !== $invoiceId
            || (int)($payment['created_at'] ?? 0) < $now - 1800
            || (int)($payment['created_at'] ?? 0) > $now + 60
            || !is_string($payment['nonce_hash'] ?? null)
            || !hash_equals($payment['nonce_hash'], hash('sha256', $nonce))
        ) {
            return null;
        }

        $token = $payment['token'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token)) {
            return null;
        }

        unset($session[$sessionKey]);

        return $token;
    }
}

if (!function_exists('paytrCheckCallbackRequest')) {
    function paytrCheckCallbackRequest(array $server, $maxBodyBytes = 16384)
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            return array('ok' => false, 'status' => 405, 'reason' => 'Method Not Allowed');
        }

        $contentLength = $server['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null
            && (!is_string($contentLength) || !preg_match('/\A\d+\z/D', $contentLength))
        ) {
            return array('ok' => false, 'status' => 400, 'reason' => 'Invalid Content Length');
        }
        if ($contentLength !== null && (float)$contentLength > (int)$maxBodyBytes) {
            return array('ok' => false, 'status' => 413, 'reason' => 'Payload Too Large');
        }

        $contentType = $server['CONTENT_TYPE'] ?? null;
        if ($contentType !== null) {
            if (!is_string($contentType)) {
                return array('ok' => false, 'status' => 415, 'reason' => 'Unsupported Media Type');
            }
            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            if ($mediaType !== 'application/x-www-form-urlencoded') {
                return array('ok' => false, 'status' => 415, 'reason' => 'Unsupported Media Type');
            }
        }

        return array('ok' => true, 'status' => 200, 'reason' => '');
    }
}

if (!function_exists('paytrCallbackRateLimit')) {
    function paytrCallbackRateLimit(
        $remoteAddress,
        $limit = 1200,
        $windowSeconds = 60,
        $now = null,
        $cacheAdd = null,
        $cacheIncrement = null
    ) {
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false
            || (int)$limit <= 0
            || (int)$windowSeconds <= 0
        ) {
            return false;
        }

        if ($cacheAdd === null || $cacheIncrement === null) {
            if (!function_exists('apcu_add')
                || !function_exists('apcu_inc')
                || (function_exists('apcu_enabled') && !apcu_enabled())
            ) {
                if (function_exists('logActivity')) {
                    static $apcuWarned = false;
                    if (!$apcuWarned) {
                        logActivity('PayTR: APCu kullanilabilir degil — callback rate limiting devre disi. php-apcu kurulumu onerilir.');
                        $apcuWarned = true;
                    }
                }
                return true;
            }

            $cacheAdd = function ($key, $value, $ttl) {
                return apcu_add($key, $value, $ttl);
            };
            $cacheIncrement = function ($key, $step) use ($windowSeconds) {
                $success = false;
                $count = apcu_inc($key, $step, $success, (int)$windowSeconds + 1);
                return $success ? $count : false;
            };
        }

        $now = $now === null ? time() : (int)$now;
        $bucket = (int)floor($now / (int)$windowSeconds);
        $key = 'paytr_cb_' . hash('sha256', $remoteAddress . '|' . $bucket);
        $ttl = (int)$windowSeconds + 1;

        if ($cacheAdd($key, 1, $ttl)) {
            return true;
        }

        $count = $cacheIncrement($key, 1);

        return $count === false || (int)$count <= (int)$limit;
    }
}

if (!function_exists('paytrIpMatchesAllowedCidrs')) {
    function paytrIpMatchesAllowedCidrs($remoteAddress, $allowedCidrs)
    {
        $allowedCidrs = trim((string)$allowedCidrs);
        if ($allowedCidrs === '') {
            return true;
        }

        $addressBinary = is_string($remoteAddress) ? @inet_pton($remoteAddress) : false;
        if ($addressBinary === false) {
            return false;
        }

        $entries = preg_split('/[\s,]+/', $allowedCidrs, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($entries as $entry) {
            $parts = explode('/', $entry, 2);
            $networkBinary = @inet_pton($parts[0]);
            if ($networkBinary === false || strlen($networkBinary) !== strlen($addressBinary)) {
                continue;
            }

            $maximumPrefix = strlen($networkBinary) * 8;
            $prefix = isset($parts[1]) ? $parts[1] : (string)$maximumPrefix;
            if (!preg_match('/\A\d{1,3}\z/D', $prefix)
                || (int)$prefix < 0
                || (int)$prefix > $maximumPrefix
            ) {
                continue;
            }

            $prefix = (int)$prefix;
            $wholeBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            if ($wholeBytes > 0
                && substr($addressBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)
            ) {
                continue;
            }

            if ($remainingBits > 0) {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
                if ((ord($addressBinary[$wholeBytes]) & $mask)
                    !== (ord($networkBinary[$wholeBytes]) & $mask)
                ) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }
}

if (!function_exists('paytrSecurityHeaders')) {
    function paytrSecurityHeaders($page, $nonce, $isHttps)
    {
        $nonce = preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', (string)$nonce)
            ? (string)$nonce
            : 'invalid';
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
            'Pragma: no-cache',
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: no-referrer',
        );

        if ($page === 'iframe') {
            $headers[] = 'X-Frame-Options: SAMEORIGIN';
            $headers[] = "Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}' https://www.paytr.com; style-src 'nonce-{$nonce}'; frame-src https://www.paytr.com; frame-ancestors 'self'; base-uri 'none'; form-action 'none'";
        }
        else {
            $headers[] = "Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; frame-ancestors 'self' https://www.paytr.com; base-uri 'none'; form-action 'none'";
        }

        if ($isHttps) {
            $headers[] = 'Strict-Transport-Security: max-age=31536000';
        }

        return $headers;
    }
}
