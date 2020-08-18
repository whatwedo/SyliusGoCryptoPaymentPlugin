<?php
/*
 * Copyright (c) 2020, whatwedo GmbH
 * All rights reserved
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Whatwedo\SyliusGoCryptoPaymentPlugin\Payum\Action;


use GuzzleHttp\RequestOptions;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Reply\HttpPostRedirect;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Whatwedo\SyliusGoCryptoPaymentPlugin\Payum\GoCryptoApi;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Sylius\Component\Core\Model\OrderInterface as SyliusOrderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class CaptureAction implements ActionInterface, ApiAwareInterface
{

    private const STAGING = 'https://ecommerce.staging.gocrypto.com';
    private const PROD = 'https://ecommerce.gocrypto.com';

    private const AUTH = '/api/auth';
    private const CHARGE = '/api/charges';

    private function getEndpoint(string $method): string
    {
        return ($this->api->isProd() ? self::PROD : self::STAGING) . $method;
    }

    /**
     * @var GoCryptoApi $api
     */
    private $api;

    /**
     * @var Client $client
     */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var SyliusPaymentInterface $payment */
        $payment = $request->getModel();
        /** @var SyliusOrderInterface $order */
        $order = $payment->getOrder();

        $returnUrl = $request->getToken()->getAfterUrl();

        try {
            // STEP 1: Authenticate
            $authResponse = $this->client->post($this->getEndpoint(self::AUTH), [
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'X-ELI-Client-Id' => $this->api->getClientId(),
                    'X-ELI-Client-Secret' => $this->api->getClientSecret(),
                    'Site-Host' => $this->api->getHost(),
                ]
            ]);
            $authJson = json_decode($authResponse->getBody(), true);
            if ($authJson['status'] === 1 && isset($authJson['data']['access_token'])) {
                // STEP 2: Create a charge
                $successToken = md5(random_bytes(100).date(DATE_RFC2822));
                $chargeResponse = $this->client->post($this->getEndpoint(self::CHARGE), [
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json',
                        'X-ELI-Access-Token' => $authJson['data']['access_token'],
                        'X-ELI-Locale' => 'DE',
                    ],
                    RequestOptions::JSON => [
                        'shop_name' => $this->api->getShopName(),
                        'amount' => [
                            'total' => $order->getTotal() / 100,
                            'currency' => 'CHF',
                        ],
                        'return_url' => $returnUrl.'&success-token='.$successToken,
                        'cancel_url' => $returnUrl,
                    ]
                ]);
                $chargeJson = json_decode($chargeResponse->getBody(), true);
                $chargeJson['success-token'] = $successToken;
                if ($chargeJson['status'] === 1 && isset($chargeJson['data']['redirect_url'])) {
                    $payment->setDetails($chargeJson);
                    throw new HttpRedirect(
                        $chargeJson['data']['redirect_url']
                    );
                } else {
                    $payment->setDetails($chargeJson);
                }
            } else {
                $payment->setDetails($authJson);
            }
        } catch (RequestException $exception) {
            $payment->setDetails(
                json_decode($exception->getResponse()->getBody(), true)
            );
        }
    }

    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof SyliusPaymentInterface
        ;
    }

    public function setApi($api): void
    {
        if (!$api instanceof GoCryptoApi) {
            throw new UnsupportedApiException('Not supported. Expected an instance of ' . GoCryptoApi::class);
        }
        $this->api = $api;
    }

}
