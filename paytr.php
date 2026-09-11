<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/paytr/security.php';

if (!defined('PAYTR_WHMCS_MODULE_VERSION')) {
    define('PAYTR_WHMCS_MODULE_VERSION', '9.0.3-sec3');
}

/**
 * @return array
 */
function paytr_MetaData()
{
    return array(
        'DisplayName' => 'PayTR Virtual Pos iFrame API (v9.0.3-sec3)',
        'APIVersion' => '1.1'
    );
}

/**
 * @return array
 */
function paytr_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'PayTR Virtual Pos iFrame API (v9.0.3-sec3)',
        ),
        'merchantID' => array(
            'FriendlyName' => 'Merchant ID',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant ID</a> here',
        ),
        'merchantKey' => array(
            'FriendlyName' => 'Merchant Key',
            'Type' => 'password',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant Key</a> here',
        ),
        'merchantSalt' => array(
            'FriendlyName' => 'Merchant Salt',
            'Type' => 'password',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant Salt</a> here',
        ),
        'iframe_v2_dark' => array(
            'FriendlyName' => 'iFrame v2 Dark Theme',
            'Type' => 'yesno',
            'Description' => 'Tick to enable dark theme for iFrame V2',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
        'callbackAllowedCidrs' => array(
            'FriendlyName' => 'Callback Allowed CIDRs',
            'Type' => 'text',
            'Size' => '80',
            'Default' => '',
            'Description' => 'Optional. Enter only current PayTR callback IP/CIDR values confirmed by PayTR support, separated by commas. Leave empty when network/WAF filtering is used.',
        ),
        'installmentFeeTaxMode' => array(
            'FriendlyName' => 'Vade Farki Vergi Ayari',
            'Type' => 'dropdown',
            'Options' => array(
                'not_taxed' => 'Vergisiz',
                'follow_invoice' => 'Faturanin Vergi Ayarini Kullan',
            ),
            'Default' => 'not_taxed',
            'Description' => 'Vade farki fatura kaleminin vergi davranisini belirler. Muhasebe politikaniza uygun secimi yapin.',
        ),
        'lang' => array(
            'FriendlyName' => 'iFrame Language',
            'Type' => 'dropdown',
            'Options' => 'Turkish,English',
            'Description' => 'Set the language of the iframe page.',
            'Default' => 'Turkish',
        ),
    );
}

function paytrNormalizeCurrency($currency)
{
    $currency = strtoupper(trim((string)$currency));

    return $currency === 'TRY' ? 'TL' : $currency;
}

function paytrBuildMerchantOid($invoiceId, $amountMinor, $currency, $isTestMode)
{
    $merchantOid = 'SP' . (int)$invoiceId
        . 'WHMCS' . time()
        . 'M' . ($isTestMode ? '1' : '0')
        . 'A' . (int)$amountMinor
        . 'C' . $currency
        . 'R' . bin2hex(random_bytes(6));

    if (strlen($merchantOid) > 64) {
        throw new \RuntimeException('PayTR siparis numarasi izin verilen uzunlugu asti.');
    }

    return $merchantOid;
}

/**
 * @param array $params
 * @return string
 */
function paytr_link($params)
{
    $ip = paytrResolveRemoteAddress($_SERVER);
    if ($ip === null) {
        logActivity('PayTR istemci IP adresi dogrulanamadi - Invoice ID: ' . (int)$params['invoiceid']);
        return '<div class="alert alert-danger">PayTR odeme oturumu guvenli sekilde olusturulamadi.</div>';
    }

    $user_basket = base64_encode(json_encode([[$params['description'], $params['amount'], 1]]));
    $no_installment = 0;
    $max_installment = 0;
    $amount = round($params['amount'] * 100);
    $address1 = $params['clientdetails']['address1'] ?? '';
    $address2 = $params['clientdetails']['address2'] ?? '';
    $city = $params['clientdetails']['city'] ?? '';
    $state = $params['clientdetails']['state'] ?? '';
    $postcode = $params['clientdetails']['postcode'] ?? '';
    $country = $params['clientdetails']['country'] ?? '';
    $currency = paytrNormalizeCurrency($params['currency'] ?? 'TL');
    $testmode = (!empty($params['testMode'])) ? 1 : 0;

    if (!in_array($currency, array('TL', 'USD', 'EUR', 'GBP', 'RUB'), true)) {
        return '<div class="alert alert-danger">PayTR bu para birimini desteklemiyor.</div>';
    }

    try {
        $merchant_oid = paytrBuildMerchantOid($params['invoiceid'], $amount, $currency, $testmode === 1);
    }
    catch (\Exception $e) {
        logActivity('PayTR siparis numarasi olusturulamadi - Invoice ID: ' . (int)$params['invoiceid']);
        return '<div class="alert alert-danger">PayTR odeme oturumu olusturulamadi.</div>';
    }

    $hash_str = $params['merchantID'] . $ip . $merchant_oid . $params['clientdetails']['email'] . $amount . $user_basket . $no_installment . $max_installment . $currency . $testmode;
    $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $params['merchantSalt'], $params['merchantKey'], true));

    $returnBaseUrl = $params['systemurl'] . 'modules/gateways/callback/paytr_return.php?invoice_id=' . (int)$params['invoiceid'];

    $post_vals = array(
        'merchant_id' => $params['merchantID'],
        'user_ip' => $ip,
        'merchant_oid' => $merchant_oid,
        'email' => $params['clientdetails']['email'],
        'payment_amount' => $amount,
        'paytr_token' => $paytr_token,
        'user_basket' => $user_basket,
        'debug_on' => $testmode,
        'no_installment' => $no_installment,
        'max_installment' => $max_installment,
        'user_name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'user_address' => $address1 . ' ' . $address2 . ' ' . $city . ' ' . $state . ' ' . $postcode . ' ' . $country,
        'user_phone' => $params['clientdetails']['phonenumber'] ?? '',
        'merchant_ok_url' => $returnBaseUrl . '&result=success',
        'merchant_fail_url' => $returnBaseUrl . '&result=failed',
        'timeout_limit' => 30,
        'currency' => $currency,
        'test_mode' => $testmode,
        'lang' => ($params['lang'] === 'Turkish') ? 'tr' : 'en',
        'iframe_v2_dark' => (!empty($params['iframe_v2_dark'])) ? 1 : 0,
    );

    $token = '';
    try {
        //$client = new Client();
        $client = new \GuzzleHttp\Client();
        $requestOptions = paytrHttpTimeouts('token');
        $requestOptions['form_params'] = $post_vals;
        $response = $client->post('https://www.paytr.com/odeme/api/get-token', $requestOptions);

        $result = json_decode($response->getBody()->getContents(), true);

        if ($result && isset($result['status']) && $result['status'] === 'success' && !empty($result['token'])) {
            $token = (string)$result['token'];
        }
        else {
            $reason = $result['reason'] ?? 'Unknown error';
            return '<div class="alert alert-danger">PAYTR IFRAME failed. Reason: '
                . htmlspecialchars((string)$reason, ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
    catch (\Exception $e) {
        logActivity('PayTR token istegi basarisiz - Invoice ID: ' . (int)$params['invoiceid'] . ' - ' . $e->getMessage());
        return '<div class="alert alert-danger">PayTR odeme sayfasina su anda ulasilamiyor. Lutfen tekrar deneyin.</div>';
    }

    $paymentNonce = paytrStorePaymentSession(
        $_SESSION,
        (int)$params['invoiceid'],
        $token
    );
    if ($paymentNonce === null) {
        logActivity('PayTR odeme oturumu saklanamadi - Invoice ID: ' . (int)$params['invoiceid']);
        return '<div class="alert alert-danger">PayTR odeme oturumu guvenli sekilde olusturulamadi.</div>';
    }

    $iframeUrl = $params['systemurl'] . 'modules/gateways/callback/paytr_iframe.php';

    return '<form method="post" action="' . htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') . '">
        <input type="hidden" name="invoice_id" value="' . (int)$params['invoiceid'] . '">
        <input type="hidden" name="paytr_nonce" value="' . htmlspecialchars($paymentNonce, ENT_QUOTES, 'UTF-8') . '">
        <button type="submit" class="btn btn-primary">'
            . htmlspecialchars((string)($params['langpaynow'] ?? 'Pay Now'), ENT_QUOTES, 'UTF-8') . '</button>
        <noscript>
            <div class="errorbox"><b>JavaScript is currently disabled or is not supported by your browser.</b><br />Please click the continue button to proceed with the processing of your transaction.</div>
            <p align="center"><input type="submit" value="Continue >>" /></p>
        </noscript>
    </form>';
}

/**
 * @param array $params
 * @return array
 */
function paytr_refund($params)
{
    $merchant_id = $params['merchantID'];
    $merchant_key = $params['merchantKey'];
    $merchant_salt = $params['merchantSalt'];
    $merchant_oid = $params['transid'];
    $return_amount = number_format((float)$params['amount'], 2, '.', '');
    $invoice_id = (int)($params['invoiceid'] ?? 0);

    if ((float)$return_amount <= 0) {
        return array(
            'status' => 'error',
            'message' => 'PayTR iade tutari sifirdan buyuk olmalidir.',
            'rawdata' => array('return_amount' => $return_amount),
        );
    }

    $reference_no = 'WHMCSR' . $invoice_id . strtoupper(bin2hex(random_bytes(8)));

    $paytr_token = base64_encode(hash_hmac('sha256', $merchant_id . $merchant_oid . $return_amount . $merchant_salt, $merchant_key, true));

    $post_vals = array(
        'merchant_id' => $merchant_id,
        'merchant_oid' => $merchant_oid,
        'return_amount' => $return_amount,
        'paytr_token' => $paytr_token,
        'reference_no' => $reference_no,
    );

    try {
        $client = new \GuzzleHttp\Client();
        $requestOptions = paytrHttpTimeouts('refund');
        $requestOptions['form_params'] = $post_vals;
        $response = $client->post('https://www.paytr.com/odeme/iade', $requestOptions);

        $result = json_decode($response->getBody()->getContents(), true);

        $responseMerchantOid = (string)($result['merchant_oid'] ?? '');
        $responseAmount = isset($result['return_amount'])
            ? number_format((float)$result['return_amount'], 2, '.', '')
            : '';

        if ($result
            && isset($result['status'])
            && $result['status'] === 'success'
            && hash_equals((string)$merchant_oid, $responseMerchantOid)
            && hash_equals($return_amount, $responseAmount)
        ) {
            $result['installment_fee_reconciliation'] = paytr_reconcile_installment_fee_refund(
                $params,
                $reference_no
            );

            return array(
                'status' => 'success',
                'rawdata' => $result,
                'transid' => $result['reference_no'] ?? $reference_no,
                'fees' => 0.00,
            );
        }
        else {
            $errMsg = $result['err_msg'] ?? 'Unknown error';
            $errNo = $result['err_no'] ?? '';
            $message = 'PayTR iade hatasi: ' . $errMsg;
            if ($errNo !== '') {
                $message .= ' (Hata kodu: ' . $errNo . ')';
            }

            paytr_log_refund_error($invoice_id, $message);

            return array(
                'status' => 'error',
                'message' => $message,
                'rawdata' => $result,
            );
        }
    }
    
    catch (\Exception $e) {
        $reconciledRefund = paytr_find_refund_by_reference(
            $merchant_id,
            $merchant_key,
            $merchant_salt,
            $merchant_oid,
            $reference_no,
            $return_amount
        );

        if ($reconciledRefund !== null) {
            $reconciledRefund['installment_fee_reconciliation'] = paytr_reconcile_installment_fee_refund(
                $params,
                $reference_no
            );

            return array(
                'status' => 'success',
                'rawdata' => $reconciledRefund,
                'transid' => $reference_no,
                'fees' => 0.00,
            );
        }

        $message = 'PayTR iade istegi gonderilemedi: ' . $e->getMessage();
        $message .= ' Tekrar denemeden once PayTR Magaza Panelinden iade durumunu kontrol edin. Referans: ' . $reference_no;
        paytr_log_refund_error($invoice_id, $message);

        return array(
            'status' => 'error',
            'message' => $message,
            'rawdata' => $e->getMessage(),
        );
    }
}

function paytr_find_refund_by_reference($merchantId, $merchantKey, $merchantSalt, $merchantOid, $referenceNo, $returnAmount)
{
    try {
        $result = paytr_get_payment_status(
            $merchantId,
            $merchantKey,
            $merchantSalt,
            $merchantOid
        );

        if (!is_array($result) || ($result['status'] ?? '') !== 'success') {
            return null;
        }

        foreach (($result['returns'] ?? array()) as $refund) {
            $refundReference = (string)($refund['reference_no'] ?? '');
            $refundAmount = number_format((float)($refund['return_amount'] ?? 0), 2, '.', '');

            if (hash_equals((string)$referenceNo, $refundReference)
                && (float)$refundAmount + 0.001 >= (float)$returnAmount
            ) {
                return array(
                    'status' => 'success',
                    'merchant_oid' => $merchantOid,
                    'return_amount' => $refundAmount,
                    'requested_return_amount' => number_format((float)$returnAmount, 2, '.', ''),
                    'reference_no' => $refundReference,
                    'reconciled_via_status_api' => true,
                );
            }
        }
    }
    catch (\Throwable $e) {
        return null;
    }

    return null;
}

function paytr_get_payment_status($merchantId, $merchantKey, $merchantSalt, $merchantOid)
{
    $token = base64_encode(hash_hmac(
        'sha256',
        $merchantId . $merchantOid . $merchantSalt,
        $merchantKey,
        true
    ));

    $client = new \GuzzleHttp\Client();
    $requestOptions = paytrHttpTimeouts('status');
    $requestOptions['form_params'] = array(
        'merchant_id' => $merchantId,
        'merchant_oid' => $merchantOid,
        'paytr_token' => $token,
    );
    $response = $client->post('https://www.paytr.com/odeme/durum-sorgu', $requestOptions);

    return json_decode($response->getBody()->getContents(), true);
}

function paytr_reconcile_installment_fee_refund(array $params, $referenceNo)
{
    $invoiceId = (int)($params['invoiceid'] ?? 0);
    $merchantOid = (string)($params['transid'] ?? '');
    $requestedAmount = round((float)($params['amount'] ?? 0), 2);

    if ($invoiceId <= 0 || $merchantOid === '' || $requestedAmount <= 0) {
        return 'not_applicable';
    }

    try {
        $paymentStatus = paytr_get_payment_status(
            $params['merchantID'],
            $params['merchantKey'],
            $params['merchantSalt'],
            $merchantOid
        );

        if (!is_array($paymentStatus) || ($paymentStatus['status'] ?? '') !== 'success') {
            return 'status_unavailable';
        }

        $principalAmount = round((float)($paymentStatus['payment_amount'] ?? 0), 2);
        $chargedAmount = round((float)($paymentStatus['payment_total'] ?? 0), 2);
        $installmentFee = round($chargedAmount - $principalAmount, 2);

        if ($principalAmount <= 0 || $installmentFee <= 0) {
            return 'not_applicable';
        }

        $originalTransaction = \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('gateway', 'paytr')
            ->where('transid', $merchantOid)
            ->where('amountin', '>', 0)
            ->first();
        if (!$originalTransaction) {
            return 'original_transaction_not_found';
        }

        $adjustmentTransId = 'PAYTRVF' . strtoupper(substr(hash('sha256', $merchantOid), 0, 24));
        $previousPrincipalRefunds = (float)\WHMCS\Database\Capsule::table('tblaccounts')
            ->where('refundid', (int)$originalTransaction->id)
            ->where('amountout', '>', 0)
            ->where('transid', '!=', $adjustmentTransId)
            ->sum('amountout');

        if (round($previousPrincipalRefunds + $requestedAmount, 2) + 0.001 < $principalAmount) {
            return 'principal_partially_refunded';
        }

        $paytrRefundTotal = 0.0;
        $currentReferenceFound = false;
        foreach (($paymentStatus['returns'] ?? array()) as $refund) {
            $paytrRefundTotal += (float)($refund['return_amount'] ?? 0);
            if (hash_equals((string)$referenceNo, (string)($refund['reference_no'] ?? ''))) {
                $currentReferenceFound = true;
            }
        }

        if (!$currentReferenceFound || round($paytrRefundTotal, 2) + 0.001 < $chargedAmount) {
            return 'paytr_full_refund_pending';
        }

        return \WHMCS\Database\Capsule::transaction(function () use (
            $invoiceId,
            $merchantOid,
            $installmentFee,
            $adjustmentTransId,
            $originalTransaction
        ) {
            $exists = \WHMCS\Database\Capsule::table('tblaccounts')
                ->where('transid', $adjustmentTransId)
                ->exists();
            if ($exists) {
                return 'already_reconciled';
            }

            $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if (!$invoice) {
                throw new \RuntimeException('WHMCS faturasi bulunamadi.');
            }

            $now = date('Y-m-d H:i:s');
            $description = 'PayTR otomatik vade farki iadesi';
            $clientCurrency = (int)\WHMCS\Database\Capsule::table('tblclients')
                ->where('id', (int)$invoice->userid)
                ->value('currency');

            \WHMCS\Database\Capsule::table('tblaccounts')->insert(array(
                'userid' => (int)$invoice->userid,
                'currency' => (int)$originalTransaction->currency,
                'gateway' => 'paytr',
                'date' => $now,
                'description' => $description . ' [' . $merchantOid . ']',
                'amountin' => 0,
                'fees' => 0,
                'amountout' => $installmentFee,
                'rate' => (float)$originalTransaction->rate,
                'transid' => $adjustmentTransId,
                'invoiceid' => $invoiceId,
                'refundid' => (int)$originalTransaction->id,
                'billingnoteid' => 0,
                'type' => 'gateway_funds_out',
                'relid' => 0,
            ));

            $billingNoteId = \WHMCS\Database\Capsule::table('tblbillingnotes')->insertGetId(array(
                'note_type' => 'credit',
                'custom_number' => '',
                'client_id' => (int)$invoice->userid,
                'date_issued' => $now,
                'subtotal' => $installmentFee,
                'tax' => 0,
                'tax2' => 0,
                'total' => $installmentFee,
                'taxrate' => 0,
                'taxrate2' => 0,
                'status' => 'closed',
                'notes' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ));

            \WHMCS\Database\Capsule::table('tblbillingnoteitems')->insert(array(
                'billingnote_id' => $billingNoteId,
                'note_type' => 'credit',
                'client_id' => (int)$invoice->userid,
                'type' => 'other',
                'relid' => 0,
                'description' => $description,
                'amount' => $installmentFee,
                'taxed' => 0,
                'tax' => null,
                'tax2' => null,
                'taxrate' => null,
                'taxrate2' => null,
                'status' => 'closed',
                'created_at' => $now,
                'updated_at' => $now,
            ));

            \WHMCS\Database\Capsule::table('tblaccounts')->insert(array(
                'userid' => (int)$invoice->userid,
                'currency' => $clientCurrency,
                'gateway' => '',
                'date' => $now,
                'description' => 'Applied Credit Note',
                'amountin' => $installmentFee,
                'fees' => 0,
                'amountout' => 0,
                'rate' => 1,
                'transid' => '',
                'invoiceid' => $invoiceId,
                'refundid' => 0,
                'billingnoteid' => $billingNoteId,
                'type' => 'invoice_billing_adjustment_credit',
                'relid' => 0,
            ));

            $totalRefunded = (float)\WHMCS\Database\Capsule::table('tblaccounts')
                ->where('refundid', (int)$originalTransaction->id)
                ->sum('amountout');
            if (round($totalRefunded, 2) + 0.001 >= round((float)$originalTransaction->amountin, 2)) {
                \WHMCS\Database\Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(array(
                        'status' => 'Refunded',
                        'date_refunded' => $now,
                        'updated_at' => $now,
                    ));
            }

            return 'reconciled_' . number_format($installmentFee, 2, '.', '');
        });
    }
    catch (\Throwable $e) {
        paytr_log_refund_error($invoiceId, 'PayTR vade farki iade uzlastirmasi basarisiz: ' . $e->getMessage());
        return 'reconciliation_failed';
    }
}

function paytr_log_refund_error($invoiceId, $message)
{
    $message = paytrBuildInvoiceNote('', $message);
    if ($message === '') {
        return;
    }

    logActivity($message . ($invoiceId > 0 ? ' - Invoice ID: ' . $invoiceId : ''));

    if ($invoiceId <= 0) {
        return;
    }

    try {
        \WHMCS\Database\Capsule::connection()->transaction(function () use ($invoiceId, $message) {
            $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first(array('notes'));

            if (!$invoice) {
                return;
            }

            \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update(array(
                    'notes' => paytrBuildInvoiceNote($invoice->notes, $message),
                ));
        });
    }
    catch (\Throwable $e) {
        logActivity('PayTR iade hata notu faturaya yazilamadi - Invoice ID: ' . $invoiceId);
    }
}
