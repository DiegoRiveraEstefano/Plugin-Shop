<?php

namespace Azuriom\Plugin\Shop\Payment\Method;

use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Illuminate\Support\Str;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DLocalMethod extends PaymentMethod
{
    /**
     * The payment method id name.
     *
     * @var string
     */
    protected $id = 'dlocalgo';

    /**
     * The payment method display name.
     *
     * @var string
     */
    protected $name = 'DLocalGo'; 

    public function startPaymentWithCountry(Cart $cart, float $amount, string $currency, string $countryInput)
    {
        $successUrl = route('shop.payments.success', $this->id);
        $failureUrl = route('shop.payments.failure', $this->id);
        $notificationUrl = route('shop.payments.notification', [$this->id, '%id%']);

        $apiKey = $this->gateway->data["api_key"];

        if ($this->gateway->data['environment'] === "production"){
            $domain = "api";
        }else{
            $domain = "api-stg";
        }
        $url = "https://checkout-{$domain}.directopago.com/v1/checkout/";

        $payment = $this->createPayment($cart, ceil($amount), $currencySelected);

        $debug = Http::withHeaders([
            'Content-Type'=> 'application/json'
        ])->post("https://discord.com/api/webhooks/1217898180335636541/sbIvAtHi2znm8wFlO7Swlokwcdw0rSruTevXySrsoEDZWR-jAmTtJbHD6x0elqdnhxh5", [
                'content' => $notificationUrl,
                'embeds' => null,
                'attachments' => [],
            ]);

        $response = Http::withHeaders([
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${apiKey}"
        ])->post($url, [
                'invoiceId' => $payment->id,
                'amount' => ceil($amount),
                'notificationUrl' => route('shop.payments.notification', ['gateway' => 'dlocalgo']),
                "successUrl" => $successUrl,
                "backUrl" => "https://mc.zgaming.net/shop/profile"
            ]);

        return redirect()->away($response["redirect_url"]);
    }

    public function startPaymentWithIp(Cart $cart, float $amount, string $currency, string $ip)
    {
        $successUrl = route('shop.payments.success', $this->id);
        $failureUrl = route('shop.payments.failure', $this->id);
        $notificationUrl = route('shop.payments.notification', [$this->id, '%id%']);
        $payment = $this->createPayment($cart, $amount, $currency);

        $locationResponse = Http::withUrlParameters([
            'endpoint' => 'https://api.iplocation.net',
            'ip' => $ip
        ])->get('{+endpoint}?ip={ip}');

        print($locationResponse->body());
        $location = $locationResponse->json()["country_code2"];
        if ($location === "-"){
            $location = "CL";
        }

        $apiKey = $this->gateway->data["api_key"];
        $secretKey = $this->gateway->data["secret_key"];

        $format_token = "{$apiKey}:{$secretKey}";

        $url = "https://api-sbx.dlocalgo.com/v1/payments/";

        $response = Http::withHeaders([
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${format_token}"
        ])->post($url, [
                'order_id' => $payment->id,
                'amount' => $amount,
                'currency' => $currency,
                'country' => "BR",
                'notification_url' => $notificationUrl,
                "success_url" => $successUrl,
                "back_url" => "https://mc.zgaming.net/shop/profile"
            ]);
            
        print($response->body());

        return redirect()->away($response["redirect_url"]);
    }

    public function startPayment(Cart $cart, float $amount, string $currency)
    {
        $successUrl = route('shop.payments.success', $this->id);
        $failureUrl = route('shop.payments.failure', $this->id);
        $notificationUrl = route('shop.payments.notification', [$this->id, '%id%']);

        $apiKey = $this->gateway->data["api_key"];

        if ($this->gateway->data['environment'] === "production"){
            $domain = "api";
        }else{
            $domain = "api-stg";
        }
        $url = "https://checkout-{$domain}.directopago.com/v1/checkout/";

        $payment = $this->createPayment($cart, ceil($amount), $currency);

        $response = Http::withHeaders([
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${apiKey}"
        ])->post($url, [
                'invoiceId' => $payment->id,
                'amount' => ceil($amount),
                'notificationUrl' => str_replace('%id%', $payment->id, $notificationUrl),
                "successUrl" => $successUrl,
                "backUrl" => "https://mc.zgaming.net/shop/profile"
        ]);
        
        return redirect()->away($response["url"]);
    }

    public function notification(Request $request, ?string $rawPaymentId)
    {

        $apiKey = $this->gateway->data["api_key"];
        if ($rawPaymentId === null){
            return response()->noContent(); 
        }

        if ($this->gateway->data['environment'] === "production"){
            $domain = "api";
        }else{
            $domain = "api-stg";
        }
        $url = "https://checkout-{$domain}.directopago.com/v1/transactions/{$rawPaymentId}";

        $paymentRequests = Http::withHeaders([ 
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${apiKey}"
        ])->get($url, []);

        $paymentId = $paymentRequests["invoice_id"];
        $orderId = $paymentRequests["transaction_id"];
        $status = $paymentRequests["status"];

        if ($status === 'CANCELLED') {
            return response()->noContent();
        }

        $payment = Payment::findOrFail($paymentId);
        $payment->transaction_id = $orderId;
        $payment->save();

        if ($status === 'PENDING') {
            $payment->update(['status' => 'pending', 'transaction_id' => $paymentId]);
            logger()->info('[Shop] Pending payment for #'.$paymentId);

            return response()->noContent();
        }

        if ($status !== 'PAID') {
            logger()->warning("[Shop] Invalid payment status for #{$paymentId}: {$status}");

            return $this->invalidPayment($payment, $paymentId, 'Invalid status');
        }
        
        #$amount = $paymentRequests['usd_amount'];

        #if ($amount < $payment->price) {
        #   logger()->warning("[Shop] Invalid payment amount or currency for #{$paymentId}: {$amount} {$currency}.");
        #
        #    return $this->invalidPayment($payment, $paymentId, 'Invalid amount');
        #}

        return $this->processPayment($payment, $paymentId);
    }

    public function view(): string
    {
        return 'shop::admin.gateways.methods.dlocalgo';
    }

    public function rules(): array
    {
        return [
            'api_key' => ['required'],
            'secret_key' => ['required'],
            'environment' => ['required']
        ];
    }
}
