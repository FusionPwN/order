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

use App\Events\ProductsUpdate;
use App\Models\Admin\Card;
use App\Models\Admin\Coupon;
use App\Models\Admin\Discount;
use App\Models\Admin\OrderCoupon;
use App\Models\Admin\OrderDiscount;
use App\Models\Admin\Prescription;
use App\Models\Admin\Product;
use App\Models\Admin\Store;
use App\Models\UserAddresses;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Konekt\Address\Contracts\AddressType;
use Konekt\Address\Models\AddressProxy;
use Konekt\Address\Models\AddressTypeProxy;
use Vanilo\Contracts\Buyable;
use Vanilo\Order\Contracts\Order;
use Vanilo\Order\Contracts\OrderFactory as OrderFactoryContract;
use Vanilo\Order\Contracts\OrderNumberGenerator;
use Vanilo\Order\Events\OrderWasCreated;
use Vanilo\Order\Exceptions\CreateOrderException;
use Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Order\Models\OrderProxy;
use Vanilo\Order\Models\OrderStatusProxy;
use Vanilo\Product\Models\ProductStateProxy;
use App\Models\Admin\OrderFee;
use Vanilo\Adjustments\Adjusters\FeePackagingBag;
use Vanilo\Adjustments\Contracts\Adjustment;

class OrderFactory implements OrderFactoryContract
{
	/** @var OrderNumberGenerator */
	protected $orderNumberGenerator;

	/* Variavel que incrementa sempre que se insere um registo na tabela order_discounts para depois associar os numeros aodesconto */
	private $countDiscount = 1;
	private $orderType = "";

	private $needs_typesense_update = false;

	public function __construct(OrderNumberGenerator $generator)
	{
		$this->orderNumberGenerator = $generator;
	}

	/**
	 * @inheritDoc
	 */
	public function createFromDataArray(array $data, array $items): Order
	{
		if (!Arr::has($data, 'type')) {
			throw new CreateOrderException(__('Wrong order type'));
		}
		if (empty($items) && Arr::get($data, 'type') == 'checkout') {
			throw new CreateOrderException(__('Can not create an order without items'));
		}

		$this->orderType = Arr::get($data, 'type');

		DB::beginTransaction();

		try {
			if (Arr::get($data, 'totalWithCard', '') == 0) {
				$data['status'] = OrderStatusProxy::PAID()->value();
			}

			if (Arr::get($data, 'type') == 'prescription') {
				$data['status'] = OrderStatusProxy::IN_CREATION()->value();
			}

			if (Arr::has($data, 'customAttributes') && Arr::has($data['customAttributes'], 'order_id')) {
				$order = OrderProxy::find(Arr::get($data['customAttributes'], 'order_id'));
			} else {
				$order = app(Order::class);

				$order->number 				= $data['number'] ?? $this->orderNumberGenerator->generateNumber($order);
				$order->user_id 			= $data['user_id'] ?? Auth::guard('web')->id();
				$order->token 				= (string) Str::uuid();

				if (Arr::has($data, 'customAttributes') && Arr::has($data['customAttributes'], 'store_id')) {
					$order->store_id = Arr::get($data['customAttributes'], 'store_id');
				} else {
					$store = Store::default()->first();
					$order->store_id = $store->id ?? null;
				}
			}

			$order->fill(Arr::except($data, ['billpayer', 'shippingAddress', 'shipping', 'payment', 'user_id']));

			if (Arr::has($data, 'status')) {
				$order->status = Arr::get($data, 'status');
			}

			if (Arr::get($data, 'type') == 'checkout' || Arr::get($data, 'type') == 'prescription') {
				$order->email 				= $data['shippingAddress']->email;
				$order->phone 				= $data['shippingAddress']->phone;

				if ($data['shippingAddress']->id == 'no-address') {
					$order->shipping_firstname 	= $data['shippingAddress']->firstname;
					$order->shipping_lastname 	= $data['shippingAddress']->lastname;
					$order->shipping_country_id = $data['shippingAddress']->country_id;
				} else {
					$order->shipping_firstname 	= $data['shippingAddress']->firstname;
					$order->shipping_lastname 	= $data['shippingAddress']->lastname;
					$order->shipping_country_id = $data['shippingAddress']->country_id;
					$order->shipping_postalcode = $data['shippingAddress']->postalcode;
					$order->shipping_city 		= $data['shippingAddress']->city;
					$order->shipping_address 	= $data['shippingAddress']->address;
				}

				if ($data['billpayer']->id != 'fatura-simplificada') {
					$order->billing_firstname 	= $data['billpayer']->firstname;
					$order->billing_lastname 	= $data['billpayer']->lastname;
					$order->billing_country_id 	= $data['billpayer']->country_id;
					$order->billing_postalcode 	= $data['billpayer']->postalcode;
					$order->billing_city 		= $data['billpayer']->city;
					$order->billing_address 	= $data['billpayer']->address;
					$order->nif 				= $data['billpayer']->nif;
				} else {
					$order->billing_simple = 1;
				}
			}

			$order->save();

			if (Arr::get($data, 'type') == 'checkout' || Arr::get($data, 'type') == 'prescription') {
				if (Auth::guard('web')->check()) {
					if ($data['shippingAddress']->id == 'new-address') {
						$this->createAddress($data['shippingAddress'], AddressTypeProxy::SHIPPING());
					}
					if ($data['billpayer']->id == 'new-address') {
						$this->createAddress($data['billpayer'], AddressTypeProxy::BILLING());
					}
				}
			}

			$freeShippingAdjustmentCoupon = null;

			$adjustments = Arr::get($data, 'adjustments', null);
			$unfoldable_items = [];
			if (null !== $adjustments) {
				$shippingAdjustment = $adjustments->byType(AdjustmentTypeProxy::SHIPPING())->first();
				$clientCardAdjustment = $adjustments->byType(AdjustmentTypeProxy::CLIENT_CARD())->first();
				$feePackageingBagAdjustment = $adjustments->byType(AdjustmentTypeProxy::FEE_PACKAGING_BAG())->first();

				foreach ($adjustments as $adjustment) {
					if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
						$coupon = Coupon::find($adjustment->getOrigin());
						OrderCoupon::create([
							'order_id' => $order->id,
							'name' => $coupon->name,
							'value' => $coupon->value,
							'type' => $coupon->type->value(),
							'code' => $coupon->code,
							'accumulative' => $coupon->accumulative,
							'associated_products' => $coupon->associated_products,
							'coupon_id' => $coupon->id
						]);

						if ($adjustment->type->value() === AdjustmentTypeProxy::COUPON_FREE_SHIPPING()->value()) {
							$freeShippingAdjustmentCoupon = $adjustment;
						}
					} else if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
						$item_id = $adjustment->getData('item_id');
						if (null !== $item_id) {
							$unfoldable_items[$item_id . 'x' . $adjustment->amount][] = $adjustment;
						}
					}
				}

				$order->original_shipping_price = (float) $shippingAdjustment->getData('amount');
				$order->shipping_price = $shippingAdjustment->getAmount();
				$order->shipping_cause = $shippingAdjustment->getData('cause');

				if (isset($freeShippingAdjustmentCoupon)) {
					$order->shipping_price = $shippingAdjustment->getAmount() + $freeShippingAdjustmentCoupon->getAmount();
					$order->shipping_cause = 'coupon';
				}

				if (isset($clientCardAdjustment)) {
					$order->card_used_balance = abs($clientCardAdjustment->getAmount());
					$card = Card::find($clientCardAdjustment->getData()['card']['id']);
					$card->temp_balance_points = $card->temp_balance_points - abs($clientCardAdjustment->getAmount());
					$card->save();
				}

				if (isset($feePackageingBagAdjustment)) {
					OrderFee::create([
						'order_id' => $order->id,
						'type'	   => FeePackagingBag::class,
						'value'	   => $feePackageingBagAdjustment->getAmount()
					]);
				}
			}

			$orderItems = Arr::where($items, function ($value, $key) {
				return $value['type'] == 'product';
			});

			if (count($unfoldable_items) > 0) {
				foreach ($unfoldable_items as $unfoldable_id => $adjustments) {
					$id = explode('x', $unfoldable_id)[0];
					$item = null;

					foreach ($orderItems as $key => $oitem) {
						if ($oitem['id'] == $id) {
							$item = $oitem;
							break;
						}
					}

					if (null !== $item) {
						$clone = $item;
						$clone['new-line'] = true;
						$clone['quantity'] = count($adjustments);

						foreach ($adjustments as $adjustment) {
							$clone['adjustments_collection'][] = $adjustment;
						}

						$orderItems[] = $clone;
					}
				}

				foreach ($unfoldable_items as $unfoldable_id => $adjustments) {
					$id = explode('x', $unfoldable_id)[0];

					foreach ($orderItems as $key => $oitem) {
						if ($oitem['id'] == $id && !array_key_exists('new-line', $oitem)) {
							unset($orderItems[$key]);
						}
					}
				}
			}

			$this->createItems(
				$order,
				array_map(function ($item) use ($freeShippingAdjustmentCoupon) {
					// Default quantity is 1 if unspecified
					$item['quantity'] = $item['quantity'] ?? 1;
					$item['discount_id'] = 0;
					$item['campaign_discount'] = 0;
					$item['coupon_discount'] = 0;

					$adjustments 			= $item['adjustments'];
					foreach ($adjustments as $adjustment) {
						$item['adjustments_collection'][] = $adjustment;
					}
					$adjustments_collection = $item['adjustments_collection'];

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

					if (isset($item['mod_price']) && $item['mod_price'] > 0) {
						$item['direct_discount'] = $item['original_price'] - $item['mod_price'];
					}

					foreach ($adjustments_collection as $adjustment) {
						if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
							$item['discount_id'] = $adjustment->getOrigin();
							$item['campaign_discount'] = $adjustment->getAmount();

							if (null !== $adjustment->getData('item_id') && $adjustment->getAmount() != 0) {
								$item['price'] = $item['product']->getPriceVat() - (float) $adjustment->getData('single_amount');
							}
						} else if (AdjustmentTypeProxy::IsCoupon($adjustment->type)) {
							$item['coupon_id'] = $adjustment->getOrigin();
							$item['coupon_discount'] = $adjustment->getAmount();
							if ($adjustment->type->value() === AdjustmentTypeProxy::COUPON_FREE_SHIPPING()->value()) {
								$freeShippingAdjustmentCoupon = $adjustment;
							}
						}
					}

					return $item;
				}, $orderItems)
			);

			if (Arr::get($data, 'type') == 'prescription') {
				$prescription_item = Arr::where($items, function ($value, $key) {
					return $value['type'] == 'prescription';
				});

				if (count($prescription_item) > 0) {
					$prescription = (object) Arr::first($prescription_item);
				} else {
					$prescription = Prescription::create([
						'info' => '',
						'obs' => ''
					]);
				}

				$order->prescription_id = $prescription->id;
			}

			$order->save();
		} catch (\Exception $e) {
			DB::rollBack();

			throw $e;
		}

		DB::commit();

		event(new OrderWasCreated($order));

		if ($this->needs_typesense_update) {
			event(new ProductsUpdate());
		}

		return $order;
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
				'cost_price'		=> $product->cost_price,
				'name' 				=> $product->getName(),
				'stock'				=> $product->getStock(),
				'vat'				=> $product->VAT_rate
			]);

			foreach ($item['adjustments_collection'] as $adjustment) {
				if (AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
					if (
						$adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO() ||
						$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD_IGUAL()
					) {
						$product_off = Product::where('sku', $adjustment->getData('sku'))->first();

						$this->_createGrift($order, $item, $product_off, $adjustment, $adjustment->getData('quantity'));
					} else if ($adjustment->type == AdjustmentTypeProxy::OFERTA_PROD()) {
						$selected_gifts = $adjustment->getData('selected_gifts');
						$counted_indexes = [];
						$gifts = collect();
						foreach ($selected_gifts as $key => $gift) {
							$qty = 1;

							foreach ($selected_gifts as $key_d => $gift_d) {
								if (!in_array($key_d, $counted_indexes) && $key_d != $key && $gift_d == $gift) {
									$qty++;
									$counted_indexes[] = $key_d;
								}
							}

							if (!in_array($key, $counted_indexes)) {
								$gifts[] = (object) [
									'id' 		=> $gift,
									'quantity' 	=> $qty
								];
							}

							$counted_indexes[] = $key;
						}

						foreach ($gifts as $gift) {
							$product_off = Product::find($gift->id);
							$this->_createGrift($order, $item, $product_off, $adjustment, $gift->quantity);
						}
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
							'type_card' => $discount->type_card,
							'value_card' => $discount->value_card,
							'type_coupon' => $discount->type_coupon,
							'value_coupon' => $discount->value_coupon,
							'start_date_coupon' => $discount->start_date_coupon,
							'end_date_coupon' => $discount->end_date_coupon,
							'offer_number' => $discount->offer_number,
							'purchase_number' => $discount->purchase_number,
							'referencia' => $discount->referencia,
							'properties' => $discount->properties,
							'num_min_buy' => $discount->num_min_buy,
							'minimum_value' => $discount->minimum_value,
							'description' => $discount->description,
							'associate' => $this->countDiscount,
							'can_stack_direct_discount' => $discount->can_stack_direct_discount
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
							'type' => $coupon->type->value(),
							'code' => $coupon->code,
							'accumulative' => $coupon->accumulative,
							'associated_products' => $coupon->associated_products,
							'coupon_id' => $coupon->id
						]
					);

					if ($coupon->isRegister()) {
						$userEnc = Auth::guard('web')->user();

						$userEnc->used_coupon = 1;
						$userEnc->save();
					}
				}
			}
		}

		if ($item['quantity'] != 0) {
			if ($this->orderType == "backoffice") {
				$oitem = $order->items()->updateOrCreate(['product_id' => $product->id, 'order_id' => $order->id], Arr::except($item, ['product', 'adjustments']));
			} else {
				$oitem = $order->items()->create($item);
			}

			if (!$oitem->product->isUnlimitedAvailability() && !$oitem->product->isLimitedAvailability()) {
				$finalStock = $product->stock - $item['quantity'];

				$arrUpdateItem = [
					'stock' => $finalStock
				];

				if ($finalStock <= 0) {
					$arrUpdateItem['state'] = ProductStateProxy::UNAVAILABLE()->value();
				}

				$oitem->product()->update($arrUpdateItem);
				$this->needs_typesense_update = true;
			}
		}
	}

	protected function _createGrift(Order $order, array $item, ?Product $product_off, Adjustment $adjustment, int $quantity)
	{
		if (null !== $product_off) {
			$free_item = array_replace([], $item); # Clonar array

			$free_item['product_id'] = $product_off->id;
			$free_item['cost_price'] = $product_off->cost_price;
			$free_item['original_price'] = $product_off->getPriceVat();
			$free_item['name'] = $product_off->name;
			$free_item['stock'] = $product_off->getStock();

			$free_item['quantity'] = $quantity;
			$free_item['price'] = 0;
			$free_item['store_discount'] = 0;
			$free_item['interval_discount'] = 0;

			$ofitem = $order->items()->updateOrCreate(['product_id' => $product_off->id, 'order_id' => $order->id], Arr::except($free_item, ['product', 'adjustments']));

			if (!$ofitem->product->isUnlimitedAvailability() && !$ofitem->product->isLimitedAvailability()) {
				$finalStock = $item['product']->stock - $free_item['quantity'];

				$arrUpdateItem = [
					'stock' => $finalStock
				];

				if ($finalStock <= 0) {
					$arrUpdateItem['state'] = ProductStateProxy::UNAVAILABLE()->value();
				}


				$ofitem->product()->update($arrUpdateItem);
				$this->needs_typesense_update = true;
			}
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

	private function createAddress($data, AddressType $type = null)
	{
		$user = Auth::guard('web')->user();

		$address = [];
		$type = is_null($type) ? AddressTypeProxy::defaultValue() : $type;
		$address['type'] = $type;
		$address['firstname'] = $data->firstname;
		$address['lastname'] = $data->lastname;
		$address['country_id'] = $data->country_id;
		$address['postalcode'] = $data->postalcode;
		$address['city'] = $data->city;
		$address['address'] = $data->address;
		$address['email'] = $data->email;
		$address['phone'] = $data->phone;

		if ($type == AddressTypeProxy::BILLING()) {
			$address['nif'] = $data->nif;
		}

		$address = $user->addresses()->create($address);

		return $address;
	}
}
