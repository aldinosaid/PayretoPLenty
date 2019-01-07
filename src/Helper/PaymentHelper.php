<?php  
namespace Payreto\Helper;

use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentPropertyRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Shipping\ServiceProvider\Contracts\ShippingServiceProviderRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Plugin\Log\Loggable;

use Payreto\Services\GatewayService;

/**
* 
*/
class PaymentHelper
{
	use Loggable;

	/**
	 * @var PaymentMethodRepositoryContract
	 */
	private $paymentMethodRepository;

	/**
	 * @var PaymentOrderRelationRepositoryContract
	 */
	private $paymentOrderRelationRepository;

	/**
	 * @var PaymentRepositoryContract
	 */
	private $paymentRepository;

	/**
	 * @var PaymentPropertyRepositoryContract
	 */
	private $paymentPropertyRepository;

	/**
	 * @var OrderRepositoryContract
	 */
	private $orderRepository;

	/**
	 *
	 * @var gatewayService
	 */
	private $gatewayService;

	/**
	 *
	 * @var shippingServiceProviders
	 */
	private $shippingServiceProviders;
	
	public function __construct(
		PaymentMethodRepositoryContract $paymentMethodRepository,
		PaymentRepositoryContract $paymentRepository,
		PaymentPropertyRepositoryContract $paymentPropertyRepository,
		PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository,
		OrderRepositoryContract $orderRepository,
		ShippingServiceProviderRepositoryContract $shippingServiceProviders,
		GatewayService $gatewayService
	)
	{
		$this->paymentMethodRepository          = $paymentMethodRepository;
		$this->paymentOrderRelationRepository   = $paymentOrderRelationRepository;
		$this->paymentRepository                = $paymentRepository;
		$this->paymentPropertyRepository        = $paymentPropertyRepository;
		$this->orderRepository                  = $orderRepository;
		$this->shippingServiceProviders 		= $shippingServiceProviders;
		$this->orderRepository 					= $orderRepository;
		$this->gatewayService = $gatewayService;
	}


	public function setAccountData($paymentResult)
	{
		$accountData = [
			'customerId' => $this->getCustomerId() ,
			'paymentGroup' => $paymentResult['paymentKey'] ,
			'brand' => $paymentResult['paymentBrand'] ,
			'holder' => '' ,
			'email' => '' ,
			'last4digits' => '',
			'expMonth' => '' ,
			'expYear' => '' ,
			'serverMode' => $paymentResult['server'] ,
			'channelId' => $paymentResult['entityId'] ,
			'refId' => $paymentResult['registrationId'] ,
			'paymentDefault' => 0 
		];


		switch ($paymentResult['paymentKey']) {
			case 'PAYRETO_ACC_RC':
				$accountData['holder'] = $paymentResult['card']['holder'];
				$accountData['last4digits'] = $paymentResult['card']['last4Digits'];
				$accountData['expMonth'] = $paymentResult['card']['expiryMonth'];
				$accountData['expYear'] = $paymentResult['card']['expiryYear'];
				break;

			case 'PAYRETO_DDS_RC':
				$accountData['holder'] = $paymentResult['bankAccount']['holder'];
				$accountData['last4digits'] = substr($paymentResult['bankAccount']['iban'], -4);
				break;

			case 'PAYRETO_PPM_RC':
				$accountData['holder'] = $paymentResult['virtualAccount']['holder'];
				$accountData['email'] = $paymentResult['virtualAccount']['accountId'];
				$accountData['refId'] = $paymentResult['id'];
				break;
		}

		return $accountData;
	}

	/**
	 * Create a debit payment when create a note (make refund).
	 *
	 * @param Payment $payment
	 * @param array $refundStatus
	 * @return Payment
	 */
	public function createPlentyRefundPayment($payment, $refundStatus)
	{
		$debitPayment = pluginApp(\Plenty\Modules\Payment\Models\Payment::class);

		$this->getLogger(__METHOD__)->error('Payreto:refundStatus', $refundStatus);

		$debitPayment->mopId = $payment->mopId;
		$debitPayment->parentId = $payment->id;
		$debitPayment->type = 'debit';
		$debitPayment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
		$debitPayment->currency = (string)$refundStatus['currency'];
		$debitPayment->amount = (string)$refundStatus['amount'];

		$state = $this->mapTransactionState('2', true);

		$this->getLogger(__METHOD__)->error('Payreto:state', $state);

		$debitPayment->status = $state;

		if ($state == Payment::STATUS_REFUNDED)
		{
			$debitPayment->unaccountable = 0;
		} else {
			$debitPayment->unaccountable = 1;
		}

		$paymentProperty = [];
		$paymentProperty[] = $this->getPaymentProperty(
						PaymentProperty::TYPE_TRANSACTION_ID,
						(string)$refundStatus['id']
		);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
		$paymentProperty[] = $this->getPaymentProperty(
						PaymentProperty::TYPE_BOOKING_TEXT,
						$this->getRefundPaymentBookingText($refundStatus)
		);
		$this->getLogger(__METHOD__)->error('Payreto:paymentProperty', $paymentProperty);

		$debitPayment->properties = $paymentProperty;
		$debitPayment->regenerateHash = true;

		$this->getLogger(__METHOD__)->error('Payreto:debitPayment', $debitPayment);

		$debitPayment = $this->paymentRepository->createPayment($debitPayment);

		return $debitPayment;
	}

	public function updateRefundStatus($status, $orderId) 
	{
		$this->orderRepository->updateOrder($status, $orderId);
	}

	public function getPaymentMethodById($paymentId) 
	{
		return $this->paymentMethodRepository->findByPaymentMethodId($paymentId);
	}

	public function mapStatus() {
		$statusConstants = $this->paymentRepository->getStatusConstants();
		$this->getLogger(__METHOD__)->error('Payreto:statusConstants', $statusConstants);
	}

	/**
	 * get domain from webstoreconfig.
	 *
	 * @return string
	 */
	public function getDomain()
	{
		$webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
		$webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
		$domain = $webstoreConfig->domainSsl;
		$this->getLogger(__METHOD__)->error('Payreto:domain', $domain);

		return $domain;
	}

	public function getShippingServiceProviderById($shippingServiceProviderId) {
		$this->getLogger(__METHOD__)->error('Payreto:shippingServiceProviderId', $shippingServiceProviderId);
		$shippingServiceProviders = $this->shippingServiceProviders->find($shippingServiceProviderId);
		$this->getLogger(__METHOD__)->error('Payreto:shippingServiceProviders', $shippingServiceProviders);
		return $shippingServiceProviders;
	}

	public function getPaymentMethodByPaymentKey($paymentKey)
	{
		if (strlen($paymentKey))
		{
			// List all payment methods for the given plugin
			$paymentMethods = $this->paymentMethodRepository->allForPlugin('Payreto');
			$this->getLogger(__METHOD__)->error('Payreto:getPaymentMethodByPaymentKey', $paymentMethods);

			if (!is_null($paymentMethods))
			{
				foreach ($paymentMethods as $paymentMethod)
				{
					if ($paymentMethod->paymentKey == $paymentKey)
					{
						return $paymentMethod;
					}
				}
			}
		}

		return null;
	}

	public function getOrderCount($customerId) 
	{
		$orderRepository = $this->orderRepository;
		$authHelper = pluginApp(AuthHelper::class);

        $orders = $authHelper->processUnguarded(
            function () use ($orderRepository, $customerId) {
                return $orderRepository->allOrdersByContact($customerId, 1, 100, ['relation', 'reference']);
            }
        );

	 	return $orders->getTotalCount();
	}

	public function getCustomerId() 
	{
		$customerService = pluginApp(\IO\Services\CustomerService::class);
		return $customerService->getContactId();
	}

	/**
	 * Create a credit payment when status_url triggered and no payment created before.
	 *
	 * @param array $paymentStatus
	 * @return Payment
	 */
	public function createPlentyPayment($paymentStatus)
	{

		$payment = pluginApp(Payment::class);

		$mopId = 0;
		$paymentMethod = $this->getPaymentMethodByPaymentKey($paymentStatus['paymentKey']);

		if (isset($paymentMethod))
		{
			$mopId = $paymentMethod->id;
		}

		$payment->mopId = (int) $mopId;
		$payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;

		$state = $this->mapTransactionState((string)$paymentStatus['status']);

		$this->getLogger(__METHOD__)->error('Payreto:mapTransactionState', $state);

		$payment->status = $state;
		$payment->currency = $paymentStatus['currency'];
		$payment->amount = $paymentStatus['amount'];

		if ($state == Payment::STATUS_CAPTURED)
		{
			$payment->unaccountable = 0;
		} else {
			$payment->unaccountable = 1;
		}

		$paymentProperty = [];
		$paymentProperty[] = $this->getPaymentProperty(
						PaymentProperty::TYPE_TRANSACTION_ID,
						$paymentStatus['transaction_id']
		);
		$paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
		$paymentProperty[] = $this->getPaymentProperty(
						PaymentProperty::TYPE_BOOKING_TEXT,
						$this->getPaymentBookingText($paymentStatus)
		);

		$payment->properties = $paymentProperty;
		$payment->regenerateHash = true;

		$payment = $this->paymentRepository->createPayment($payment);

		$this->getLogger(__METHOD__)->error('Payreto:payment_create_plenty', $payment);

		return $payment;
	}

	/**
	 * get Variation Description
	 *
	 * @param BasketItem $basketItem
	 * @return string
	 */
	public function getVariationSalesPrice($variationId)
	{
		$variationSalesPrice = pluginApp(\Plenty\Modules\Item\VariationSalesPrice\Contracts\VariationSalesPriceRepositoryContract::class);
		$authHelper = pluginApp(AuthHelper::class);

        $variationSalesPriceItem = $authHelper->processUnguarded(
            function () use ($variationSalesPrice, $variationId) {
                return $variationSalesPrice->findByVariationId($variationId);
            }
        );

		return $variationSalesPriceItem;
	}

	/**
	 * get Variation Description
	 *
	 * @param BasketItem $basketItem
	 * @return string
	 */
	public function getVariationDescription($variationId)
	{
		$variationDescriptionContract = pluginApp(\Plenty\Modules\Item\VariationDescription\Contracts\VariationDescriptionRepositoryContract::class);
		$authHelper = pluginApp(AuthHelper::class);

        $variationDescription = $authHelper->processUnguarded(
            function () use ($variationDescriptionContract, $variationId) {
                return $variationDescriptionContract->findByVariationId($variationId);
            }
        );

		return $variationDescription;
	}

	/**
	 * update the payment by transaction_id when status_url triggered if payment already created before.
	 * create a payment if no payment created before.
	 *
	 * @param array $paymentStatus
	 */
	public function updatePlentyPayment($paymentStatus)
	{
		$payment = $this->createPlentyPayment($paymentStatus);
		$this->getLogger(__METHOD__)->error('Payreto:payment_update_plenty', $payment);

		$this->assignPlentyPaymentToPlentyOrder($payment, (int) $paymentStatus['orderId']);
	}

	/**
	 * check if the mopId is Payreto mopId.
	 *
	 * @param number $mopId
	 * @return bool
	 */
	public function isPayretoPaymentMopId($mopId)
	{
		$paymentMethods = $this->paymentMethodRepository->allForPlugin('Payreto');
		
		$this->getLogger(__METHOD__)->error('Payreto:isPayretoPaymentMopId', $paymentMethods);

		if (!is_null($paymentMethods))
		{
			foreach ($paymentMethods as $paymentMethod)
			{
				if ($paymentMethod->id == $mopId)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function isPaymentRecurring($paymentMethod) 
    {
        switch ($paymentMethod) {
            case 'PAYRETO_PPM_RC':
            case 'PAYRETO_DDS_RC':
            case 'PAYRETO_ACC_RC':
                return true;
            default:
                return false;
        }
    }

	/**
	 * update payment property value.
	 *
	 * @param array $properties
	 * @param int $propertyType
	 * @param string $value
	 */
	public function updatePaymentPropertyValue($properties, $propertyType, $value)
	{
		if (count($properties) > 0)
		{
			foreach ($properties as $property)
			{
				if ($property->typeId == $propertyType)
				{
					$paymentProperty = $property;
					break;
				}
			}

			if (isset($paymentProperty))
			{
				$paymentProperty->value = $value;
				$this->paymentPropertyRepository->changeProperty($paymentProperty);
			}
		}
	}

	/**
	 * get payment property value.
	 *
	 * @param array $properties
	 * @param int $propertyType
	 * @return null|string
	 */
	public function getPaymentPropertyValue($properties, $propertyType)
	{
		$this->getLogger(__METHOD__)->error('Payreto:getPaymentPropertyValue', $properties);

		if (count($properties) > 0)
		{
			foreach ($properties as $property)
			{
				if ($property instanceof PaymentProperty)
				{
					if ($property->typeId == $propertyType)
					{
						return $property->value;
					}
				}
			}
		}

		return null;
	}

	/**
	 * get order payment status by transactionId (success or error)
	 *
	 * @param string $transactionId
	 * @return array
	 */
	public function getOrderPaymentStatus($transactionId)
	{
		$this->getLogger(__METHOD__)->error('Payreto:transactionId', $transactionId);

		$payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(
						PaymentProperty::TYPE_TRANSACTION_ID,
						$transactionId
		);

		$status = '';
		$properties = [];

		if (count($payments) > 0)
		{
			foreach ($payments as $payment)
			{
				$status = $payment->status;
				$properties = $payment->properties;
				break;
			}
		}

		$this->getLogger(__METHOD__)->error('Payreto:status', $status);

		if ($status == Payment::STATUS_REFUSED)
		{
			$failedReasonCode = $this->getPaymentPropertyValue(
							$properties,
							PaymentProperty::TYPE_EXTERNAL_TRANSACTION_STATUS
			);

			return [
				'type' => 'error',
				'value' => 'The payment has been failed : ' 
			];
		}

		return [
			'type' => 'success',
			'value' => 'The payment has been executed successfully.'
		];
	}

	/**
	 * get refund payment booking text (use for show refund payment detail information).
	 *
	 * @param array $refundStatus
	 * @return string
	 */
	public function getRefundPaymentBookingText($refundStatus)
	{
		$paymentBookingText = [];

		if (is_array($refundStatus))
		{
			$mbTransactionId = (string)$refundStatus['id'];
			$resultCode = $refundStatus['result']['code'];
		}
		else
		{
			$mbTransactionId = (string)$refundStatus->id;
			$resultCode = (string)$refundStatus->result->code;
		}
		if (isset($mbTransactionId))
		{
			$paymentBookingText[] = "Transaction ID : " . $mbTransactionId;
		}
		if (isset($resultCode))
		{
			$paymentBookingText[] = "Refund status : " . $this->getPaymentMessage($resultCode);
		}
		if (!empty($paymentBookingText))
		{
			return implode("\n", $paymentBookingText);
		}

		return '';
	}

	/**
	 * Get payment status order transaction
	 * @param String $paymentType
	 * @return int
	 * 
	 * Status :
	 * 1. Awaiting Approval -> In-review
	 * 2. Approved -> Pre-Authorization
	 * 3. Captured -> DB/RC/CP
	 */
	public function getPaymentStatus($paymentType) 
	{
		if ($paymentType === 'PA') {
			return $this->mapTransactionState('2');
		} elseif ($paymentType === 'CP' || $paymentType === 'RC' || $paymentType === 'DB') {
			return $this->mapTransactionState('3');
		} else {
			return $this->mapTransactionState('1');
		}
	}

	/**
	 * get payment status (use for payment/refund detail information status).
	 *
	 * @param array $resultCode
	 * @return string
	 */
	public function getPaymentMessage(string $resultCode)
	{
		$paymentResult = $this->gatewayService->getTransactionResult($resultCode);
		$isSuccessReview = $this->gatewayService->isSuccessReview($resultCode);
		if ($isSuccessReview) {
			return 'Pending';
		} else {
			switch ($paymentResult)
			{
				case 'ACK':
					return 'Processed';
					break;
				case 'NOK':
					return 'Failed';
					break;
				default :
					return 'Canceled';
					break;
			}
		}
	}

	/**
	 * Returns the plentymarkets payment status matching the given payment response status.
	 *
	 * @param string $status
	 * @param bool $isRefund
	 * @return int
	 */
	public function mapTransactionState(string $status, $isRefund = false)
	{
		$statusConstants = $this->paymentRepository->getStatusConstants();

		$this->getLogger(__METHOD__)->error('Payreto:statusConstants', $statusConstants);
		switch ($status)
		{
			case '1':
				return Payment::STATUS_AWAITING_APPROVAL;
			case '2':
				if ($isRefund)
				{
					return Payment::STATUS_REFUNDED;
				}
				return Payment::STATUS_APPROVED;
			case '-2':
				return Payment::STATUS_REFUSED;
			case '3':
				return Payment::STATUS_CAPTURED;
			case '5':
				return Payment::STATUS_CANCELED;
		}

		return Payment::STATUS_AWAITING_APPROVAL;
	}

	/**
	 * Returns a PaymentProperty with the given params
	 *
	 * @param int $typeId
	 * @param string $value
	 * @return PaymentProperty
	 */
	private function getPaymentProperty($typeId, $value)
	{
		$paymentProperty = pluginApp(PaymentProperty::class);

		$paymentProperty->typeId = $typeId;
		$paymentProperty->value = (string) $value;
		
		$this->getLogger(__METHOD__)->error('Payreto:paymentProperty', $paymentProperty);

		return $paymentProperty;
	}


	/**
	 * Assign the payment to an order in plentymarkets.
	 *
	 * @param Payment $payment
	 * @param int $orderId
	 */
	public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId)
	{
		$orderRepository = $this->orderRepository;
		$authHelper = pluginApp(AuthHelper::class);
		$order = $authHelper->processUnguarded(
						function () use ($orderRepository, $orderId) {
							return $orderRepository->findOrderById($orderId);
						}
		);

		if (!is_null($order) && $order instanceof Order)
		{
			$this->getLogger(__METHOD__)->error('Payreto:payment', $payment);
			$this->getLogger(__METHOD__)->error('Payreto:order', $order);
			$this->paymentOrderRelationRepository->createOrderRelation($payment, $order);
		}
	}

	/**
	 * get payment booking text (use for show payment detail information).
	 *
	 * @param array $paymentStatus
	 * @param bool 
	 * @return string
	 */
	public function getPaymentBookingText($paymentStatus)
	{
		$paymentBookingText = [];
		$countryRepository = pluginApp(CountryRepositoryContract::class);

		$this->getLogger(__METHOD__)->error('Payreto:countryRepository', $countryRepository);
		$this->getLogger(__METHOD__)->error('Payreto:paymentStatus', $paymentStatus);

		if (isset($paymentStatus['transaction_id']))
		{
			$paymentBookingText[] = "Transaction ID : " . (string) $paymentStatus['transaction_id'];
		}
		if (isset($paymentStatus['payment_type']))
		{
			if ($paymentStatus['payment_type'] == 'NGP')
			{
				$paymentStatus['payment_type'] = 'OBT';
			}
			$paymentMethod = $this->getPaymentMethodByPaymentKey('PAYRETO_' . $paymentStatus['payment_type']);
			if (isset($paymentMethod))
			{
				$paymentBookingText[] = "Used payment method : " . $paymentMethod->name;
			}
		}
		if (isset($paymentStatus['status']))
		{
			$paymentBookingText[] = "Payment status : " .
				$this->getPaymentMessage($paymentStatus['result']['code']);
		}
		if (isset($paymentStatus['IP_country']))
		{
			$ipCountry = $countryRepository->getCountryByIso($paymentStatus['IP_country'], 'isoCode2');
			$paymentBookingText[] = "Order originated from : " . $ipCountry->name;
		}
		if (isset($paymentStatus['payment_instrument_country']))
		{
			$paymentInstrumentCountry = $countryRepository->getCountryByIso(
							$paymentStatus['payment_instrument_country'],
							'isoCode3'
			);
			$paymentBookingText[] = "Country (of the card-issuer) : " . $paymentInstrumentCountry->name;
		}
		if (isset($paymentStatus['pay_from_email']))
		{
			$paymentBookingText[] = "Payreto account email : " . $paymentStatus['pay_from_email'];
		}
		if (!empty($paymentBookingText))
		{
			return implode("\n", $paymentBookingText);
		}

		return '';
	}

}

?>