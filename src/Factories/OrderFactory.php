<?php

declare(strict_types=1);
/**
 * Contains the OrderFactory class.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-11-30
 *
 */

namespace Vanilo\Order\Factories;

use App\Models\Admin\Coupon;
use App\Models\Admin\Discount;
use App\Models\Admin\OrderCoupon;
use App\Models\Admin\OrderDiscount;
use App\Models\Admin\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Konekt\Address\Contracts\AddressType;
use Konekt\Address\Models\AddressProxy;
use Konekt\Address\Models\AddressTypeProxy;
use Vanilo\Contracts\Address;
use Vanilo\Contracts\Buyable;
use Vanilo\Order\Contracts\Billpayer;
use Vanilo\Order\Contracts\Order;
use Vanilo\Order\Contracts\OrderFactory as OrderFactoryContract;
use Vanilo\Order\Contracts\OrderNumberGenerator;
use Vanilo\Order\Events\OrderWasCreated;
use Vanilo\Order\Exceptions\CreateOrderException;
use Auth;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;

class OrderFactory implements OrderFactoryContract
{
	/** @var OrderNumberGenerator */
	protected $orderNumberGenerator;

	/* Variavel que incrementa sempre que se insere um registo na tabela order_discounts para depois associar os numeros aodesconto */
	private $countDiscount = 1; 

	public function __construct(OrderNumberGenerator $generator)
	{
		$this->orderNumberGenerator = $generator;
	}

	/**
	 * @inheritDoc
	 */
	public function createFromDataArray(array $data, array $items): Order
	{
		dd('order');
		if (empty($items)) {
			throw new CreateOrderException(__('Can not create an order without items'));
		}

		DB::beginTransaction();

		try {
			$order = app(Order::class);

			$order->fill(Arr::except($data, ['billpayer', 'shippingAddress', 'shipping', 'payment']));
			$order->number = $data['number'] ?? $this->orderNumberGenerator->generateNumber($order);
			$order->user_id = $data['user_id'] ?? Auth::guard('web')->id();
			$order->token = (string) Str::uuid();
			$order->save();

			$freeShippingAdjustmentCoupon = null;

			foreach ($data['adjustments'] as $adjustment) {
				if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
					$coupon = Coupon::find($adjustment->getOrigin());
					OrderCoupon::create([
						'order_id' => $order->id,
						'name' => $coupon->name,
						'value' => $coupon->value,
						'type' => $coupon->type,
						'code' => $coupon->code,
						'accumulative' => $coupon->accumulative,
						'associated_products' => $coupon->associated_products,
						'coupon_id' => $coupon->id
					]);

					if ($adjustment->type->value() === AdjustmentTypeProxy::COUPON_FREE_SHIPPING()->value()) {
						$freeShippingAdjustmentCoupon = $adjustment;
					}
				}
			}

			$this->createBillpayer($order, $data);
			$this->createShippingAddress($order, $data);

			$this->createItems(
				$order,
				array_map(function ($item) use ($freeShippingAdjustmentCoupon) {
					// Default quantity is 1 if unspecified
					$item['quantity'] = $item['quantity'] ?? 1;
					$item['discount_id'] = 0;

					$adjustments = $item['adjustments'];

					$interval_discount_adjustment = $adjustments->byType(AdjustmentTypeProxy::INTERVAL_DISCOUNT())->first();
					if (isset($interval_discount_adjustment)) {
						$item['interval_discount'] = $interval_discount_adjustment->getAmount();
					}

					$store_discount_adjustment = $adjustments->byType(AdjustmentTypeProxy::STORE_DISCOUNT())->first();
					if (isset($store_discount_adjustment)) {
						$item['store_discount'] = $store_discount_adjustment->getAmount();
					}

					$direct_discount_adjustment = $adjustments->byType(AdjustmentTypeProxy::DIRECT_DISCOUNT())->first();
					if (isset($direct_discount_adjustment)) {
						$item['direct_discount'] = $direct_discount_adjustment->getAmount();
					}

					foreach ($adjustments->getIterator() as $adjustment) {
						if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
							$item['discount_id'] = $adjustment->getOrigin();
						} else if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
							$item['coupon_id'] = $adjustment->getOrigin();

							if ($adjustment->type->value() === AdjustmentTypeProxy::COUPON_FREE_SHIPPING()->value()) {
								$freeShippingAdjustmentCoupon = $adjustment;
							}
						}
					}

					return $item;
				}, $items)
			);

			$shippingAdjustment = $data['adjustments']->byType(AdjustmentTypeProxy::SHIPPING())->first();

			if (isset($freeShippingAdjustmentCoupon)) {
				$order->shipping_price = $shippingAdjustment->getAmount() + $freeShippingAdjustmentCoupon->getAmount();
			} else {
				$order->shipping_price = $shippingAdjustment->getAmount();
			}

			$order->save();
		} catch (\Exception $e) {
			DB::rollBack();

			throw $e;
		}

		DB::commit();

		event(new OrderWasCreated($order));

		return $order;
	}

	protected function createShippingAddress(Order $order, array $data)
	{
		if ($address = isset($data['shippingAddress'])) {
			$order->shippingAddress()->associate(
				$this->createOrCloneAddress($data['shippingAddress'], AddressTypeProxy::SHIPPING())
			);
		}
	}

	protected function createBillpayer(Order $order, array $data)
	{
		if (isset($data['billpayer'])) {
			$address = $this->createOrCloneAddress($data['billpayer']['address'], AddressTypeProxy::BILLING());

			$billpayer = app(Billpayer::class);
			$billpayer->fill(Arr::except($data['billpayer'], 'address'));
			$billpayer->address()->associate($address);
			$billpayer->save();

			$order->billpayer()->associate($billpayer);
		}
	}

	protected function createItems(Order $order, array $items)
	{
		$that = $this;
		$hasBuyables = collect($items)->contains(function ($item) use ($that) {
			return $that->itemContainsABuyable($item);
		});

		if (!$hasBuyables) { // This is faster
			$order->items()->createMany($items);
		} else {
			foreach ($items as $item) {
				$this->createItem($order, $item);
			}
		}
	}

	/**
	 * Creates a single item for the given order
	 *
	 * @param Order $order
	 * @param array $item
	 */
	protected function createItem(Order $order, array $item)
	{
		if ($this->itemContainsABuyable($item)) {
			/** @var Buyable $product */
			$product = $item['product'];
			$item = array_merge($item, [
				'product_type' 		=> $product->morphTypeName(),
				'product_id' 		=> $product->getId(),
				'original_price' 	=> $product->getPriceVat(),
				'name' 				=> $product->getName(),
				'stock'				=> $product->getStock()
			]);

			foreach ($item['adjustments']->getIterator() as $adjustment) {
				if (AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
					if (
						$adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO() ||
						$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD_IGUAL() ||
						$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD()
					) {

						$free_item = array_replace([], $item); # Clonar array

						if ($adjustment->type == AdjustmentTypeProxy::OFERTA_PROD()) {
							$product = Product::where('sku', $adjustment->getData('sku'))->first();
							$free_item['product_id'] = $product->id;
							$free_item['original_price'] = $product->getPriceVat();
							$free_item['name'] = $product->name;
							$free_item['stock'] = $product->getStock();
						} else {
							if ($adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO()) {
								$item['quantity'] -= $adjustment->getData('quantity');
							}
						}

						$free_item['quantity'] = $adjustment->getData('quantity');
						$free_item['price'] = 0;
						$free_item['store_discount'] = 0;
						$free_item['interval_discount'] = 0;

						unset($free_item['product']);
						unset($free_item['adjustments']);

						$order->items()->create($free_item);
					}
				}

				if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
					$discount = Discount::find($adjustment->getOrigin());

					$verifyOrder = OrderDiscount::where('order_id', $order->id)->where('discount_id', $discount->id)->first();

					if (isset($verifyOrder)) {
						$this->countDiscount--;
					}

					OrderDiscount::updateOrCreate(
						[
							'order_id' => $order->id,
							'discount_id' => $discount->id,
						],
						[
							'tag_discount_type' => $discount->get_tipo_nome->tag,
							'discount_type_name' => $discount->get_tipo_nome->name,
							'name' => $discount->name,
							'label_name' => $discount->label_name,
							'start_date' => $discount->start_date,
							'end_date' => $discount->end_date,
							'discount_type' => $discount->discount_type,
							'value' => $discount->value,
							'offer_number' => $discount->offer_number,
							'purchase_number' => $discount->purchase_number,
							'referencia' => $discount->referencia,
							'discount_1' => $discount->discount_1,
							'discount_2' => $discount->discount_2,
							'discount_3' => $discount->discount_3,
							'discount_4' => $discount->discount_4,
							'discount_5' => $discount->discount_5,
							'num_min_buy' => $discount->num_min_buy,
							'minimum_value' => $discount->minimum_value,
							'description' => $discount->description,
							'associate' => $this->countDiscount
						]
					);

					$this->countDiscount++;
				} else if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
					$coupon = Coupon::find($adjustment->getOrigin());
					OrderCoupon::updateOrCreate(
						[
							'order_id' => $order->id,
						],
						[
							'name' => $coupon->name,
							'value' => $coupon->value,
							'type' => $coupon->type,
							'code' => $coupon->code,
							'accumulative' => $coupon->accumulative,
							'associated_products' => $coupon->associated_products,
							'coupon_id' => $coupon->id
						]
					);
				}
			}

			unset($item['product']);
			unset($item['adjustments']);
		}

		if ($item['quantity'] != 0) {
			$order->items()->create($item);
		}
	}

	/**
	 * Returns whether an instance contains a buyable object
	 *
	 * @param array $item
	 *
	 * @return bool
	 */
	private function itemContainsABuyable(array $item)
	{
		return isset($item['product']) && $item['product'] instanceof Buyable;
	}

	private function addressToAttributes(Address $address)
	{
		return [
			'name' => $address->getName(),
			'postalcode' => $address->getPostalCode(),
			'country_id' => $address->getCountryCode(),
			/** @todo Convert Province code to province_id */
			'city' => $address->getCity(),
			'address' => $address->getAddress(),
		];
	}

	private function createOrCloneAddress($address, AddressType $type = null)
	{
		if ($address instanceof Address) {
			$address = $this->addressToAttributes($address);
		} elseif (!is_array($address)) {
			throw new CreateOrderException(
				sprintf(
					'Address data is %s but it should be either an Address or an array',
					gettype($address)
				)
			);
		}

		$type = is_null($type) ? AddressTypeProxy::defaultValue() : $type;
		$address['type'] = $type;
		$address['name'] = empty(Arr::get($address, 'name')) ? '-' : $address['name'];

		return AddressProxy::create($address);
	}
}