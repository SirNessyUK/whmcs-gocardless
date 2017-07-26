<?php
/*
Copyright (c) 2017, Maartje Eyskens at The Innovating Group LLP
All rights reserved.
*/

// Require libraries needed for gateway module functions.
use WHMCS\Database\Capsule;
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../../modules/gateways/gocardless.php';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables("gocardless");
// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$headers = getallheaders();

// Handle the redirect flow callback
if (!empty($_GET["redirect_flow_id"])) {
    $gocardless = getConfiguredGoCardless($gatewayParams);
    $redirectFlow = $gocardless->redirectFlows()->complete($_GET["redirect_flow_id"],
    [
        "params" => ["session_token" => $_SESSION["gc_session_token"]]
    ]);
    update_query(
        "tblclients",
        array(
            'cardtype' => "GoCardless",
            "gatewayid" => $redirectFlow->links->mandate
        ),
        array("id" => $_SESSION["gc_params"]['clientdetails']["userid"])
    );

    header("Location: " . $gatewayParams["systemurl"] . "/viewinvoice.php?id=" . $_SESSION["gc_params"]['invoiceid']);

    unset($_SESSION["gc_session_token"]);
    unset($_SESSION["gc_params"]);
    exit();
}

// Handle payment
if (!empty($_GET["paynow"])) {
    if (!array_key_exists("gc_params", $_SESSION)) {
        die("Invalid transaction");
    }
    $params = $_SESSION["gc_params"];
    $gocardless = getConfiguredGoCardless($gatewayParams);

    $token = bin2hex(openssl_random_pseudo_bytes(64));

    $userInfo = Capsule::table('tblclients')
        ->where('id', $params['clientdetails']["userid"])
        ->get();
    
    if (empty($userInfo[0]->gatewayid) && $userInfo[0]->cardtype != "GoCardless") {
        die("Invalid transaction");
    }

    $payment = $gocardless->payments()->create([
        "params" => [
            "amount" => floatval($params['amount']) * 100,
            "currency" => $params['currency'],
            "links" => [
                "mandate" => $userInfo[0]->gatewayid
            ],
            "metadata" => [
                "invoice_number" => (string)$params['invoiceid']
            ]
        ],
        "headers" => [
            "Idempotency-Key" => $token
        ]
    ]);

    addInvoicePayment($params['invoiceid'],$payment->id,$params['amount'],gocardless_getFee($params['amount']),"gocardless");

    header("Location: " . $params["systemurl"] . "/viewinvoice.php?id=" . $params['invoiceid']);
    
    unset($_SESSION["gc_session_token"]);
    unset($_SESSION["gc_params"]);

    exit();
}

// Handle webhooks
if (!empty($headers["Webhook-Signature"])) {
    $token = $gatewayParams["webhookSignature"];
    $raw_payload = file_get_contents('php://input');
    $provided_signature = $headers["Webhook-Signature"];
    $calculated_signature = hash_hmac("sha256", $raw_payload, $token);
    if ($provided_signature != $calculated_signature) {
        header("HTTP/1.1 498 Invalid Token");
        exit();
    }
    $payload = json_decode($raw_payload, true);

    // Each webhook may contain multiple events to handle, batched together
    foreach ($payload["events"] as $event) {
        print("Processing event " . $event["id"] . "\n");

        switch ($event["resource_type"]) {
            case "mandates":
                gocardless_process_mandate_event($event);
            break;
            case "payments":
                gocardless_process_payment_event($event);
            break;
            default:
                print("Don't know how to process an event with resource_type " . $event["resource_type"] . "\n");
                break;
         }
    }
    exit();
}