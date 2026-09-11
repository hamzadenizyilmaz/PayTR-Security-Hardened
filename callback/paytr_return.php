<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../paytr/security.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$result = isset($_GET['result']) ? strtolower((string)$_GET['result']) : '';

if ($invoiceId <= 0 || !in_array($result, array('success', 'failed'), true)) {
    die("Invalid payment return");
}

$invoiceUrl = rtrim($CONFIG['SystemURL'] ?? '', '/') . '/viewinvoice.php?id=' . $invoiceId;
$isSuccessReturn = $result === 'success';
$title = $isSuccessReturn ? 'Ödeme Kontrol Ediliyor' : 'Ödeme Tamamlanamadı';
$message = $isSuccessReturn
    ? 'Ödemeniz PayTR tarafına iletildi. Fraud ve banka kontrolleri tamamlandıktan sonra fatura durumu otomatik olarak güncellenecektir.'
    : 'Ödeme işlemi tamamlanamadı. Faturanızı tekrar açarak yeniden ödeme deneyebilirsiniz.';
$alertClass = $isSuccessReturn ? 'info' : 'danger';
$cspNonce = bin2hex(random_bytes(16));
$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
foreach (paytrSecurityHeaders('return', $cspNonce, $isHttps) as $securityHeader) {
    header($securityHeader);
}

?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <style nonce="<?php echo $cspNonce; ?>">
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f6f8;
            color: #1f2933;
            font-family: Arial, Helvetica, sans-serif;
        }
        .paytr-return {
            width: min(560px, calc(100% - 32px));
            padding: 28px;
            border: 1px solid #d9e2ec;
            border-radius: 6px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            text-align: center;
        }
        .paytr-return h1 {
            margin: 0 0 12px;
            font-size: 24px;
            line-height: 1.25;
        }
        .paytr-return p {
            margin: 0 0 22px;
            font-size: 15px;
            line-height: 1.55;
        }
        .paytr-return.info {
            border-top: 4px solid #2563eb;
        }
        .paytr-return.danger {
            border-top: 4px solid #dc2626;
        }
        .paytr-return a {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 4px;
            background: #2563eb;
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="paytr-return <?php echo htmlspecialchars($alertClass, ENT_QUOTES, 'UTF-8'); ?>">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="<?php echo htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_top">Faturaya Dön</a>
    </main>
    <script nonce="<?php echo $cspNonce; ?>">
        if (window.top !== window.self) {
            window.top.location.replace(window.location.href);
        }
    </script>
</body>
</html>
