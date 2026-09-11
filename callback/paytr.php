<?php

require_once __DIR__ . '/../paytr/security.php';

/**
 * PayTR WHMCS Gateway Callback
 */

$preflight = paytrCheckCallbackRequest($_SERVER);
if (!$preflight['ok']) {
    http_response_code($preflight['status']);
    if ($preflight['status'] === 405) {
        header('Allow: POST');
    }
    die('PAYTR: ' . $preflight['reason']);
}

$callbackRemoteAddress = paytrResolveRemoteAddress($_SERVER);
if ($callbackRemoteAddress === null) {
    http_response_code(400);
    die('PAYTR: Invalid Remote Address');
}

$configuredRateLimit = (int)getenv('PAYTR_CALLBACK_RATE_LIMIT');
if ($configuredRateLimit < 60 || $configuredRateLimit > 10000) {
    $configuredRateLimit = 1200;
}
if (!paytrCallbackRateLimit($callbackRemoteAddress, $configuredRateLimit, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    die('PAYTR: Rate Limit Exceeded');
}

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'paytr';

function paytrCallbackFail(
    $gatewayName,
    array $payload,
    $reason,
    $statusCode = 400,
    $isAuthenticated = true
)
{
    $logPayload = paytrCallbackFailureLogPayload($isAuthenticated, $payload);
    if ($logPayload !== null) {
        logTransaction($gatewayName, $logPayload, $reason);
    }
    http_response_code((int)$statusCode);
    die('PAYTR: ' . $reason);
}

function paytrCallbackOk()
{
    http_response_code(200);
    echo 'OK';
    exit;
}

function paytrFormatCurrency($currency)
{
    $currency = strtoupper(trim((string)$currency));

    return $currency === 'TRY' ? 'TL' : $currency;
}

function paytrParseMinorAmount($amount)
{
    if (!is_scalar($amount) || !preg_match('/^\d+$/', (string)$amount)) {
        return null;
    }

    return (int)$amount;
}

function paytrMinorToDecimal($amountMinor)
{
    return round(((int)$amountMinor) / 100, 2);
}

function paytrQueryPaymentStatus(array $gatewayParams, $merchantOid)
{
    $token = base64_encode(hash_hmac(
        'sha256',
        $gatewayParams['merchantID'] . $merchantOid . $gatewayParams['merchantSalt'],
        $gatewayParams['merchantKey'],
        true
    ));

    $client = new \GuzzleHttp\Client();
    $requestOptions = paytrHttpTimeouts('status');
    $requestOptions['form_params'] = array(
        'merchant_id' => $gatewayParams['merchantID'],
        'merchant_oid' => $merchantOid,
        'paytr_token' => $token,
    );
    $response = $client->post('https://www.paytr.com/odeme/durum-sorgu', $requestOptions);

    $result = json_decode($response->getBody()->getContents(), true);
    if (!is_array($result) || ($result['status'] ?? '') !== 'success') {
        $errorCode = (string)($result['err_no'] ?? 'unknown');
        throw new \RuntimeException('PayTR durum sorgusu basarisiz. Hata kodu: ' . $errorCode);
    }

    $paymentStatus = paytrParsePaymentStatusResponse($result);
    if ($paymentStatus === null) {
        throw new \RuntimeException('PayTR durum sorgusu gecersiz odeme verisi dondurdu.');
    }

    return $paymentStatus;
}

function paytrGetInvoiceData($invoiceId)
{
    return \WHMCS\Database\Capsule::table('tblinvoices')
        ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
        ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
        ->where('tblinvoices.id', $invoiceId)
        ->select(
            'tblinvoices.id',
            'tblinvoices.userid',
            'tblinvoices.total',
            'tblinvoices.status',
            'tblinvoices.taxrate',
            'tblinvoices.taxrate2',
            'tblcurrencies.code as currency_code'
        )
        ->first();
}

function paytrCallbackLogData(array $payload, $isTestMode)
{
    $allowedFields = array(
        'merchant_oid',
        'status',
        'total_amount',
        'payment_type',
        'payment_amount',
        'currency',
        'installment_count',
        'merchant_id',
        'test_mode',
        'failed_reason_code',
        'failed_reason_msg',
    );
    $logData = array();

    foreach ($allowedFields as $field) {
        if (isset($payload[$field]) && is_scalar($payload[$field])) {
            $logData[$field] = substr((string)$payload[$field], 0, 512);
        }
    }

    if ($isTestMode) {
        $logData['paytr_transaction_environment'] = 'PayTR magazaniz test modundadir.';
    }

    return $logData;
}

function paytrTransactionExists($transactionId)
{
    return \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('transid', $transactionId)
        ->exists();
}

function paytrAddInvoiceTestModeNote($invoiceId, $transactionId)
{
    $connection = \WHMCS\Database\Capsule::connection();

    $connection->transaction(function () use ($invoiceId, $transactionId) {
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first(array('notes'));

        if (!$invoice || strpos((string)$invoice->notes, $transactionId) !== false) {
            return;
        }

        $note = 'PayTR magazaniz test modundadir. Test odeme basarili gorundu ancak gercek tahsilat olmadigi icin fatura odenmis isaretlenmedi. Islem: ' . $transactionId;
        $notes = trim((string)$invoice->notes . "\n" . $note);

        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(array('notes' => $notes));
    });
}

function paytrAddInstallmentFeeInvoiceItem($invoiceId, $feeAmount, $installmentCount, $taxMode)
{
    global $CONFIG;

    $feeAmount = round((float)$feeAmount, 2);
    if ($feeAmount <= 0) {
        return;
    }

    $invoiceBefore = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(array('total', 'taxrate', 'taxrate2'));
    if (!$invoiceBefore) {
        throw new \RuntimeException('WHMCS faturasi bulunamadi.');
    }

    $hasTaxedItem = \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('taxed', 1)
        ->exists();
    $taxed = $taxMode === 'follow_invoice'
        && !empty($CONFIG['TaxEnabled'])
        && $hasTaxedItem
        && ((float)$invoiceBefore->taxrate > 0 || (float)$invoiceBefore->taxrate2 > 0);
    $itemAmount = $feeAmount;

    if ($taxed && strcasecmp((string)($CONFIG['TaxType'] ?? 'Exclusive'), 'Inclusive') !== 0) {
        $taxRate = max(0, (float)$invoiceBefore->taxrate) / 100;
        $taxRate2 = max(0, (float)$invoiceBefore->taxrate2) / 100;
        $compound = !empty($CONFIG['TaxL2Compound']);
        $taxFactor = 1 + $taxRate + ($compound ? $taxRate2 * (1 + $taxRate) : $taxRate2);
        $itemAmount = round($feeAmount / max(1, $taxFactor), 2);
    }

    $description = 'Vade Farki (' . (int)$installmentCount . ' Taksit) - PayTR';
    $result = localAPI('UpdateInvoice', array(
        'invoiceid' => $invoiceId,
        'newitemdescription' => array($description),
        'newitemamount' => array(number_format($itemAmount, 2, '.', '')),
        'newitemtaxed' => array($taxed),
    ));

    if (($result['result'] ?? '') !== 'success') {
        throw new \RuntimeException('WHMCS vade farki fatura kalemini ekleyemedi.');
    }

    $invoiceAfter = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(array('total'));
    $actualDifference = round((float)$invoiceAfter->total - (float)$invoiceBefore->total, 2);
    $roundingDifference = round($feeAmount - $actualDifference, 2);

    if (abs($roundingDifference) >= 0.01) {
        $roundingResult = localAPI('UpdateInvoice', array(
            'invoiceid' => $invoiceId,
            'newitemdescription' => array('Vade Farki Vergi Yuvarlama'),
            'newitemamount' => array(number_format($roundingDifference, 2, '.', '')),
            'newitemtaxed' => array(false),
        ));

        if (($roundingResult['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS vade farki vergi yuvarlamasini ekleyemedi.');
        }
    }

    $finalTotal = (float)\WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->value('total');
    if (abs(round($finalTotal - (float)$invoiceBefore->total, 2) - $feeAmount) >= 0.01) {
        throw new \RuntimeException('WHMCS vade farki toplami PayTR tahsilatiyla eslesmedi.');
    }
}

function paytrAppendTransactionDescription($invoiceId, $transactionId, $gatewayModuleName, $description)
{
    if ($description === '') {
        return;
    }

    $transaction = \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where('transid', $transactionId)
        ->where('gateway', $gatewayModuleName)
        ->first(array('id', 'description'));

    if (!$transaction || strpos((string)$transaction->description, $description) !== false) {
        return;
    }

    $newDescription = trim((string)$transaction->description . ' [' . $description . ']');
    \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('id', $transaction->id)
        ->update(array('description' => $newDescription));
}

$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

if (!paytrIpMatchesAllowedCidrs(
    $callbackRemoteAddress,
    $gatewayParams['callbackAllowedCidrs'] ?? ''
)) {
    http_response_code(403);
    die('PAYTR: Callback Source Not Allowed');
}

$callbackPayload = paytrReadCallbackPayload($_POST);
$isTestModeCallback = is_array($callbackPayload) && $callbackPayload['test_mode'];
$callbackLogData = paytrCallbackLogData($_POST, $isTestModeCallback);

if ($callbackPayload === null) {
    paytrCallbackFail(
        $gatewayParams['name'],
        $callbackLogData,
        'Callback Rejected',
        400,
        false
    );
}

$hash = $callbackPayload['hash'];
$merchantOid = $callbackPayload['merchant_oid'];
$status = $callbackPayload['status'];
$totalAmount = $callbackPayload['total_amount'];
$paymentAmount = $callbackPayload['payment_amount'];
$currency = $callbackPayload['currency'];

if (!paytrVerifyCallbackHash(
    $merchantOid,
    $status,
    $totalAmount,
    $gatewayParams['merchantSalt'],
    $gatewayParams['merchantKey'],
    $hash
)) {
    paytrCallbackFail(
        $gatewayParams['name'],
        $callbackLogData,
        'Callback Rejected',
        400,
        false
    );
}

if (!in_array($status, array('success', 'failed'), true)) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Payment Status');
}

$orderContext = paytrParseMerchantOid($merchantOid);
if ($orderContext === null) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Merchant Order Context');
}

$invoiceId = checkCbInvoiceID($orderContext['invoice_id'], $gatewayParams['name']);
$invoice = paytrGetInvoiceData($invoiceId);
if (!$invoice) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invoice Lookup Failure');
}

if ($status === 'failed') {
    logTransaction($gatewayParams['name'], $callbackLogData, 'Failed');
    paytrCallbackOk();
}

if (paytrTransactionExists($merchantOid)) {
    logTransaction($gatewayParams['name'], $callbackLogData, 'Duplicate - Already Processed');
    paytrCallbackOk();
}

$callbackPaymentMinor = paytrParseMinorAmount($paymentAmount);
$callbackTotalMinor = paytrParseMinorAmount($totalAmount);
if ($callbackPaymentMinor === null || $callbackTotalMinor === null) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Callback Amount');
}

try {
    $verifiedPayment = paytrQueryPaymentStatus($gatewayParams, $merchantOid);
}
catch (\Throwable $e) {
    logActivity('PayTR odeme durum sorgusu basarisiz - Invoice ID: ' . $invoiceId . ' - ' . $e->getMessage());
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Callback Processing Error', 503);
}

if ($callbackPaymentMinor !== $verifiedPayment['payment_minor']
    || $callbackTotalMinor !== $verifiedPayment['total_minor']
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Amount Mismatch');
}

if ($verifiedPayment['total_minor'] < $verifiedPayment['payment_minor']) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Verified Charged Amount Mismatch');
}

if (!paytrInvoiceAmountMatches($invoice->total, $verifiedPayment['payment_minor'])) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invoice Amount Mismatch');
}

$callbackCurrency = paytrFormatCurrency($currency);
if ($callbackCurrency !== $verifiedPayment['currency']
    || $verifiedPayment['currency'] !== paytrFormatCurrency($invoice->currency_code)
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Currency Mismatch');
}

if ($isTestModeCallback !== $verifiedPayment['test_mode']) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Test Mode Mismatch');
}

if ($orderContext['amount_minor'] !== $verifiedPayment['payment_minor']
    || $orderContext['currency'] !== $verifiedPayment['currency']
    || $orderContext['test_mode'] !== $verifiedPayment['test_mode']
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Signed Order Context Mismatch');
}

if ($verifiedPayment['test_mode']) {
    paytrAddInvoiceTestModeNote($invoiceId, $merchantOid);
    logTransaction($gatewayParams['name'], $callbackLogData, 'Test Payment Verified - Invoice Not Paid');
    paytrCallbackOk();
}

$chargedAmount = paytrMinorToDecimal($verifiedPayment['total_minor']);
$installmentFee = paytrMinorToDecimal(
    $verifiedPayment['total_minor'] - $verifiedPayment['payment_minor']
);
$installmentCount = $verifiedPayment['installment_count'];
$installmentFeeTaxMode = (string)($gatewayParams['installmentFeeTaxMode'] ?? 'not_taxed');

try {
    $wasProcessed = \WHMCS\Database\Capsule::connection()->transaction(function () use (
        $invoiceId,
        $invoice,
        $merchantOid,
        $installmentFee,
        $installmentCount,
        $chargedAmount,
        $gatewayModuleName,
        $callbackCurrency,
        $installmentFeeTaxMode
    ) {
        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first(array('id'));

        if (paytrTransactionExists($merchantOid)) {
            return false;
        }

        if ($installmentFee > 0) {
            paytrAddInstallmentFeeInvoiceItem(
                $invoiceId,
                $installmentFee,
                $installmentCount,
                $installmentFeeTaxMode
            );
        }

        addInvoicePayment($invoiceId, $merchantOid, $chargedAmount, 0, $gatewayModuleName);

        if ($installmentFee > 0) {
            paytrAppendTransactionDescription(
                $invoiceId,
                $merchantOid,
                $gatewayModuleName,
                'Vade Farki: ' . number_format($installmentFee, 2, '.', '') . ' ' . $callbackCurrency . ', Taksit: ' . $installmentCount
            );
        }

        return true;
    });
}
catch (\Throwable $e) {
    logActivity('PayTR callback islenemedi - Invoice ID: ' . $invoiceId . ' - ' . $e->getMessage());
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Payment Recording Failure', 500);
}

logTransaction(
    $gatewayParams['name'],
    $callbackLogData,
    $wasProcessed ? 'Successful' : 'Duplicate - Already Processed'
);

paytrCallbackOk();
