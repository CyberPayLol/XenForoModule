<?php

namespace CyberPay\PaygateCyberPay\Payment;

use XF;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class CyberPay extends AbstractProvider
{
	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return 'CyberPay';
	}

	/**
	 * @return string
	 */
	public function getApiEndpoint(): string
	{
		return 'https://api.cyberpay.lol/';
	}

	/**
	 * @param array $options
	 * @param array $errors
	 *
	 * @return bool
	 */
	public function verifyConfig(array &$options, &$errors = []): bool
	{
		if (empty($options['shop_uuid']))
		{
			$errors[] = XF::phrase('cp_pg_cp_you_must_provide_all_data');
		}

		if (!$errors)
		{
			return true;
		}

		return false;
	}

	/**
	 * @param PurchaseRequest $purchaseRequest
	 * @param Purchase        $purchase
	 *
	 * @return array
	 */
	protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase): array
	{
		$paymentProfileOptions = $purchase->paymentProfile->options;

		if (strpos(round($purchase->cost, 2), '.') !== false) {
			return [
				'shop_to' => $paymentProfileOptions['shop_uuid'],
				'sum'       => round($purchase->cost, 2),
				'comment'   => $purchase->title,
				'custom_fields'  => $purchaseRequest->request_key,
				'hook_url'    => $this->getCallbackUrl(),
				'expire'    => 1900
			];
		} else {
			return [
				'shop_to' => $paymentProfileOptions['shop_uuid'],
				'sum'       => round($purchase->cost, 2).'.00',
				'comment'   => $purchase->title,
				'custom_fields'  => $purchaseRequest->request_key,
				'hook_url'    => $this->getCallbackUrl(),
				'expire'    => 1900
			];
		}
	}

	/**
	 * @param Controller      $controller
	 * @param PurchaseRequest $purchaseRequest
	 * @param Purchase        $purchase
	 *
	 * @return XF\Mvc\Reply\Error|XF\Mvc\Reply\Redirect
	 */
	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): XF\Mvc\Reply\AbstractReply
	{
		$payment = $this->getPaymentParams($purchaseRequest, $purchase);

		// Запрос на создание инвойса
		$response = XF::app()->http()->client()->post($this->getApiEndpoint() . 'payment/create', [
			'json' => $payment,
			'exceptions'  => false
		]);

		if ($response)
		{
			$responseData = json_decode($response->getBody()->getContents(), true);
			if (!empty($responseData['status']) && $responseData['status'] == 'error')
			{
				if ($responseData['message'] == 'sum is too big') {
					return $controller->error(XF::phrase('cp_pg_cp_sum_is_too_big'));
				}

				if ($responseData['message'] == 'sum is too small') {
					return $controller->error(XF::phrase('cp_pg_cp_sum_is_too_small'));
				}
			}

			if (!empty($responseData['url']))
			{
				return $controller->redirect($responseData['url']);
			}
		}

		return $controller->error(XF::phrase('something_went_wrong_please_try_again'));
	}

	/**
	 * @param Request $request
	 *
	 * @return CallbackState
	 */
	public function setupCallback(Request $request): CallbackState
	{
		$state = new CallbackState();

		$jsonArray = json_decode($request->getInputRaw(), true) ?? [];

		$state->input = $request->getInputFilterer()->filterArray(['bill' => $jsonArray], [
			'bill' => 'array'
		]);

		$state->transactionId = $state->input['bill']['invoice_id'] ?? null;
		$state->subscriberId = $state->input['bill']['customer']['email'] ?? null;
		$state->requestKey = $state->input['bill']['custom_fields'] ?? null;

		$state->costAmount = $state->input['bill']['amount'] ?? null;

		$state->ip = $request->getIp();

		$state->httpCode = 200;

		return $state;
	}

	/**
	 * @param CallbackState $state
	 */
	public function prepareLogData(CallbackState $state): void
	{
		$state->logDetails = array_merge($state->input, [
			'ip'           => $state->ip,
			'request_time' => XF::$time
		]);
	}

	/**
 	 * @param CallbackState $state
 	 */
	public function getPaymentResult(CallbackState $state): void
	{
    	$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
	}

	/**
	 * @var array
	 */
	protected $supportedCurrencies = [
		'RUB'
	];

	/**
	 * @param PaymentProfile $paymentProfile
	 * @param                $unit
	 * @param                $amount
	 * @param int            $result
	 *
	 * @return bool
	 */
	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
	{
		$result = self::ERR_NO_RECURRING;

		return false;
	}

	/**
	 * @return array
	 */
	protected function getSupportedRecurrenceRanges(): array
	{
		return [];
	}

	/**
	 * @param PaymentProfile $paymentProfile
	 * @param                $currencyCode
	 *
	 * @return bool
	 */
	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode): bool
	{
		$addOns = XF::app()->container('addon.cache');

		if (!empty($paymentProfile->options['cp_cu_enable_exchange'])
			&& array_key_exists('CP/CurrencyUtils', $addOns)
			&& XF::registry()->exists('cpCurrencies'))
		{
			return array_key_exists($currencyCode, XF::registry()->get('cpCurrencies'));
		}

		return in_array($currencyCode, $this->supportedCurrencies);
	}
}
