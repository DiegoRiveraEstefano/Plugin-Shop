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

class DlocalMethod extends PaymentMethod
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

        $formatedCountry = explode(":", $countryInput);

        $country = $formatedCountry[0];
        $currencySelected = $formatedCountry[1];

        $conversionAmount = Http::withHeaders([ 
            'Authorization'=> 'Token  8f134e6ad7c0f5d0d4906fc304d46ca930dce35b', 
        ]) 
        ->get("http://209.222.97.223:27029/exchange/USD-{$currencySelected}"); 

        $ratio = $conversionAmount->json()["ratio"];

        $apiKey = $this->gateway->data["api_key"];
        $secretKey = $this->gateway->data["secret_key"];

        $format_token = "{$apiKey}:{$secretKey}";

        $url = "https://api-sbx.dlocalgo.com/v1/payments/";

        $payment = $this->createPayment($cart, $amount * $ratio, $currencySelected);

        print(str_replace('%id%', '{payment_id}', $notificationUrl));

        $response = Http::withHeaders([
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${format_token}"
        ])->post($url, [
                'order_id' => $payment->id,
                'amount' => $amount * $ratio,
                'currency' => $currencySelected,
                'country' => $country,
                'notification_url' => str_replace('%id%', '{payment_id}', $notificationUrl),
                "success_url" => $successUrl,
                "back_url" => "https://mc.zgaming.net/shop/profile"
            ]);
            
        print($response->body());

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
        $payment = $this->createPayment($cart, $amount, $currency);

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
                'country' => "CL",
                'notification_url' => $notificationUrl,
                "success_url" => $successUrl,
                "back_url" => "https://mc.zgaming.net/shop/profile"
            ]);

        return redirect()->away($response["redirect_url"]);
    }

    public function notification(Request $request, ?string $rawPaymentId)
    {

        $apiKey = $this->gateway->data["api_key"];
        $secretKey = $this->gateway->data["secret_key"];

        $format_token = "{$apiKey}:{$secretKey}";

        $response = Http::withHeaders([ 
            'Content-Type'=> 'application/json', 
            'Authorization'=> "Bearer ${format_token}"
        ]) 
        ->get("https://api.dlocalgo.com/v1/payments/{$rawPaymentId}"); 

        $paymentId = $request->input('id');
        $amount = $request->input('amount');
        $currency = $request->input('currency');
        $country = $request->input('country');
        $orderId = $request->input('order_id');
        $status = $request->input('status');

        if ($status === 'CANCELLED') {
            return response()->noContent();
        }

        $payment = Payment::findOrFail($orderId);

        if ($status === 'PENDING') {
            $payment->update(['status' => 'pending', 'transaction_id' => $paymentId]);
            logger()->info('[Shop] Pending payment for #'.$paymentId);

            return response()->noContent();
        }

        if ($status !== 'PAID') {
            logger()->warning("[Shop] Invalid payment status for #{$paymentId}: {$status}");

            return $this->invalidPayment($payment, $paymentId, 'Invalid status');
        }

        if ($currency !== $payment->currency || $amount != $payment->price) {
            logger()->warning("[Shop] Invalid payment amount or currency for #{$paymentId}: {$amount} {$currency}.");

            return $this->invalidPayment($payment, $paymentId, 'Invalid amount/currency');
        }

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
            'secret_key' => ['required']
        ];
    }
}
