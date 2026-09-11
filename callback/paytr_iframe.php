<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../paytr/security.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'paytr';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (empty($gatewayParams['type'])) {
    die("Module Not Activated");
}

$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
if ($invoiceId <= 0) {
    http_response_code(400);
    die("Invalid payment request");
}

$currentUser = new \WHMCS\Authentication\CurrentUser();
$client = $currentUser->client();
$invoiceOwnerId = (int)\WHMCS\Database\Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->value('userid');

if (!$client || $invoiceOwnerId <= 0 || (int)$client->id !== $invoiceOwnerId) {
    http_response_code(403);
    die("Payment session is not authorized");
}

$nonce = $_POST['paytr_nonce'] ?? '';
$token = is_array($_SESSION ?? null)
    ? paytrConsumePaymentSession($_SESSION, $invoiceId, $nonce)
    : null;

if ($token === null) {
    http_response_code(403);
    die("Payment session expired");
}

$cspNonce = bin2hex(random_bytes(16));
$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
foreach (paytrSecurityHeaders('iframe', $cspNonce, $isHttps) as $securityHeader) {
    header($securityHeader);
}

?>

<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo htmlspecialchars($gatewayParams['name']); ?></title>
    <style nonce="<?php echo $cspNonce; ?>">
        body { margin: 0; padding: 0; background-color: #f8f9fa; }
        #paytriframe { border: 0; min-height: 600px; width: 100%; }
    </style>
</head>
<body>
    <script nonce="<?php echo $cspNonce; ?>" src="https://www.paytr.com/js/iframeResizer.min.js"></script>
    <iframe src="https://www.paytr.com/odeme/guvenli/<?php echo htmlspecialchars($token); ?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
    <script nonce="<?php echo $cspNonce; ?>">
        iFrameResize({
            log: false,
            checkOrigin: ['https://www.paytr.com']
        }, '#paytriframe');
    </script>
</body>
</html>
