<?php

namespace Azuriom\Plugin\Shop\Payment\Method;

use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Azuriom\Plugin\Shop\Payment\CurrenciesToCountries;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DlocalMethod extends PaymentMethod
{
    /**
     * The payment method id name.
     *
     * @var string
     */
    protected $id = 'dlocal';

    /**
     * The payment method display name.
     *
     * @var string
     */
    protected $name = 'DLocalGo';

    public function startPayment(Cart $cart, float $amount, string $currency)
    {

        $successUrl = route('shop.payments.success', $this->id);
        $failureUrl = route('shop.payments.failure', $this->id);
        $notificationUrl = route('shop.payments.notification', [$this->id, '%id%']);

        $payment = $this->createPayment($cart, $amount, $currency);


        $response = $this->prepareRequest()->post('https://api-sbx.dlocalgo.com/v1/payments', [
            'order_id' => $payment->id,
            'amount' => $amount,
            'currency' => $currency,
            'country' => CurrenciesToCountries.countrieList[$currency],
            'notification_url' => $notificationUrl,
            "success_url" => $successUrl,
            "back_url" => "https://mc.zgaming.net/shop/profile",
        ]);



        return redirect()->away($response["redirect_url"]);
    }

    public function notification(Request $request, ?string $rawPaymentId)
    {
        $data = ['cmd' => '_notify-validate'] + $request->all();

        $response = Http::asForm()->post('https://ipnpb.paypal.com/cgi-bin/webscr', $data);

        if ($response->body() !== 'VERIFIED') {
            return response()->json('Invalid response from PayPal', 401);
        }

        $paymentId = $request->input('txn_id');
        $amount = $request->input('mc_gross');
        $currency = $request->input('mc_currency');
        $status = $request->input('payment_status');
        $caseType = $request->input('case_type');
        $receiverEmail = Str::lower($request->input('receiver_email'));

        if ($status === 'Canceled_Reversal' || $caseType !== null) {
            return response()->noContent();
        }

        if ($status === 'Reversed') {
            $parentTransactionId = $request->input('parent_txn_id');

            $payment = Payment::firstWhere('transaction_id', $parentTransactionId);

            return $this->processChargeback($payment);
        }

        $payment = Payment::findOrFail($request->input('custom'));

        if ($status === 'Pending') {
            $payment->update(['status' => 'pending', 'transaction_id' => $paymentId]);
            logger()->info('[Shop] Pending payment for #'.$paymentId);

            return response()->noContent();
        }

        if ($status !== 'Completed') {
            logger()->warning("[Shop] Invalid payment status for #{$paymentId}: {$status}");

            return $this->invalidPayment($payment, $paymentId, 'Invalid status');
        }

        if ($currency !== $payment->currency || $amount < $payment->price) {
            logger()->warning("[Shop] Invalid payment amount or currency for #{$paymentId}: {$amount} {$currency}.");

            return $this->invalidPayment($payment, $paymentId, 'Invalid amount/currency');
        }

        $email = Str::lower($this->gateway->data['email']);

        if ($receiverEmail !== $email) {
            logger()->warning("[Shop] Invalid email for #{$paymentId}: expected {$email} but got {$receiverEmail}.");

            return $this->invalidPayment($payment, $paymentId, 'Invalid email');
        }

        return $this->processPayment($payment, $paymentId);
    }

    public function view(): string
    {
        return 'shop::admin.gateways.methods.paypal';
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
