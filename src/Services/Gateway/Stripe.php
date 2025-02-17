<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Config;
use App\Models\Paylist;
use App\Services\Auth;
use App\Services\Exchange;
use App\Services\View;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RedisException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe as StripeSDK;
use Stripe\Webhook;
use UnexpectedValueException;
use voku\helper\AntiXSS;

final class Stripe extends Base
{
    public function __construct()
    {
        $this->antiXss = new AntiXSS();
    }

    public static function _name(): string
    {
        return 'stripe';
    }

    public static function _enable(): bool
    {
        return self::getActiveGateway('stripe');
    }

    public static function _readableName(): string
    {
        return 'Stripe';
    }

    public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $price = $this->antiXss->xss_clean($request->getParam('price'));
        $invoice_id = $this->antiXss->xss_clean($request->getParam('invoice_id'));
        $trade_no = self::generateGuid();

        if ($price < Config::obtain('stripe_min_recharge') ||
            $price > Config::obtain('stripe_max_recharge')
        ) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '非法的金额',
            ]);
        }

        $user = Auth::getUser();

        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->invoice_id = $invoice_id;
        $pl->tradeno = $trade_no;
        $pl->gateway = self::_readableName();
        $pl->save();

        try {
            $exchange_amount = (new Exchange())->exchange((float) $price, 'CNY', Config::obtain('stripe_currency'));
        } catch (GuzzleException|RedisException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '汇率获取失败',
            ]);
        }

        StripeSDK::setApiKey(Config::obtain('stripe_api_key'));
        $session = null;

        try {
            $session = Session::create([
                'customer_email' => $user->email,
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => Config::obtain('stripe_currency'),
                            'product_data' => [
                                'name' => 'Invoice #' . $invoice_id,
                            ],
                            'unit_amount' => (int) ($exchange_amount * 100),
                        ],
                        'quantity' => 1,
                    ],
                ],
                'mode' => 'payment',
                'payment_intent_data' => [
                    'metadata' => [
                        'trade_no' => $trade_no,
                    ],
                ],
                'success_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice_id . '/view',
                'cancel_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice_id . '/view',
            ]);
        } catch (ApiErrorException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'Stripe API error',
            ]);
        }

        return $response->withHeader('Location', $session->url)->withJson([
            'ret' => 1,
            'msg' => '订单发起成功，正在跳转到支付页面...',
        ]);
    }

    public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        try {
            $event = Webhook::constructEvent(
                $request->getBody()->getContents(),
                $request->getHeaderLine('Stripe-Signature'),
                Config::obtain('stripe_endpoint_secret')
            );
        } catch (UnexpectedValueException) {
            return $response->withStatus(400)->withJson([
                'ret' => 0,
                'msg' => 'Unexpected Value error',
            ]);
        } catch (SignatureVerificationException) {
            return $response->withStatus(400)->withJson([
                'ret' => 0,
                'msg' => 'Signature Verification error',
            ]);
        }

        $payment_intent = $event->data->object;

        if ($event->type === 'payment_intent.succeeded' && $payment_intent->status === 'succeeded') {
            $this->postPayment($payment_intent->metadata->trade_no);

            return $response->withJson([
                'ret' => 1,
                'msg' => 'Payment success',
            ]);
        }

        return $response->withJson([
            'ret' => 0,
            'msg' => 'Payment failed',
        ]);
    }

    /**
     * @throws Exception
     */
    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('gateway/stripe.tpl');
    }
}
