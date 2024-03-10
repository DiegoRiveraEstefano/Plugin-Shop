<?php

namespace Azuriom\Plugin\Shop\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Gateway;
use Azuriom\Plugin\Shop\Payment\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function payment(Request $request)
    {
        $cart = Cart::fromSession($request->session());
        // If the cart isn't empty and the total is 0, just complete
        // the payment now as gateways won't accept null payment
        if (! $cart->isEmpty() && $cart->total() < 0.1) {
            PaymentManager::createPayment($cart, $cart->total(), currency(), 'free')->deliver();

            $cart->destroy();

            return to_route('shop.home')->with('success', trans('shop::messages.cart.success'));
        }

        $gateways = Gateway::enabled()
            ->get()
            ->filter(function ($gateway) {
                if (! payment_manager()->hasPaymentMethod($gateway->type)) {
                    return false;
                }

                return ! $gateway->paymentMethod()->hasFixedAmount();
            }); 
            
        return view('shop::payments.pay', ['gateways' => $gateways, "country" => $request->input('country')]);
    }

    /**
     * Start a new payment.
     */
    public function pay(Request $request, Gateway $gateway)
    {
        abort_if(! $gateway->is_enabled, 403);
        $cart = Cart::fromSession($request->session());

        if ($cart->isEmpty()) {
            return to_route('shop.cart.index');
        }
        if ($gateway["type"] === "dlocalgo"){
            return $gateway->paymentMethod()->startPaymentWithCountry($cart, $cart->total(), currency(), $request->input('country'));
        }
        return $gateway->paymentMethod()->startPayment($cart, $cart->total(), currency());
    }

    public function success(Request $request, Gateway $gateway)
    {
        $response = $gateway->paymentMethod()->success($request);

        $cart = Cart::fromSession($request->session());

        $cart->destroy();

        return $response;
    }

    public function failure(Request $request, Gateway $gateway)
    {
        return $gateway->paymentMethod()->failure($request);
    }
}
