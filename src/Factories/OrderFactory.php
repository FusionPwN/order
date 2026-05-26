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

use App\Classes\Utilities;
use App\Events\OrderStatusChanged;
use App\Events\ProductUpdate;
use App\Generators\DocumentNumberGenerator;
use App\Models\Admin\Card;
use App\Models\Admin\Coupon;
use App\Models\Admin\Discount;
use App\Models\Admin\OrderCoupon;
use App\Models\Admin\OrderDiscount;
use App\Models\Admin\Prescription;
use App\Models\Admin\Product;
use App\Models\Admin\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Konekt\Address\Contracts\AddressType;
use Konekt\Address\Models\AddressTypeProxy;
use Vanilo\Contracts\Buyable;
use Vanilo\Order\Contracts\Order;
use Vanilo\Order\Contracts\OrderFactory as OrderFactoryContract;
use Vanilo\Order\Contracts\OrderNumberGenerator;
use Vanilo\Order\Events\OrderWasCreated;
use Vanilo\Order\Exceptions\CreateOrderException;
use Auth;
use Illuminate\Support\Str;
use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Order\Models\OrderProxy;
use Vanilo\Order\Models\OrderStatusProxy;
use Vanilo\Product\Models\ProductStateProxy;
use App\Models\Admin\OrderFee;
use Vanilo\Adjustments\Adjusters\FeePackagingBag;
use Vanilo\Adjustments\Contracts\Adjustment;
use Vanilo\Cart\Helpers\Modifier;
use Illuminate\Support\Facades\Log;
use Vanilo\Order\Models\OrderItemProxy;

class OrderFactory implements OrderFactoryContract
{
	/** @var OrderNumberGenerator */
	protected $orderNumberGenerator;
	protected $documentNumberGenerator;

	/* Variavel que incrementa sempre que se insere um registo na tabela order_discounts para depois associar os numeros aodesconto */
	private $countDiscount = 1;
	private $orderType = "";

	protected array $products_to_update = [];

	public function __construct(OrderNumberGenerator $generator, DocumentNumberGenerator $documentNumberGenerator)
	{
		$this->orderNumberGenerator 	= $generator;
		$this->documentNumberGenerator 	= $documentNumberGenerator;
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
				if ($this->orderType == "backoffice") {
					$order->user_id = $data['user_id'] ?? NULL;
				} else {
					$order->user_id = $data['user_id'] ?? Auth::guard('web')->id();
				}
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
				$freeProductCouponAdjustment = $adjustments->byType(AdjustmentTypeProxy::COUPON_FREE_PRODUCT())->first();
				$paymentAdjustment = $adjustments->byType(AdjustmentTypeProxy::PAYMENT_FEE())->first();


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
							'min_value_only_for_aplicable' => $coupon->min_value_only_for_aplicable,
							'coupon_id' => $coupon->id,
							'affiliate_user_id' => $coupon->affiliate_user_id,
							'commission_value' => $coupon->commission_value,
							'commission_value_type' => $coupon->commission_value_type,
							'commission_type' => $coupon->commission_type,
							'validate_domains' => $coupon->validate_domains,
							'domains' => $coupon->domains
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

				if (!session()->has('list_code')) {
					$order->original_shipping_price = (float) $shippingAdjustment->getData('amount');
					$order->shipping_price = $shippingAdjustment->getAmount();
					$order->shipping_cause = $shippingAdjustment->getData('cause');
				}

				if (isset($paymentAdjustment)) {
					$order->payment_fee = $paymentAdjustment->getAmount();
				}

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
						'type'	   => AdjustmentTypeProxy::FEE_PACKAGING_BAG()->value(),
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

			if (isset($freeProductCouponAdjustment)) {
				$dummy_item = $orderItems[0];
				$dummy_item['id'] = 'coupon-gift-dummy-item';
				$dummy_item['adjustments'] = collect([$freeProductCouponAdjustment]);
				$dummy_item['adjustments_collection'] = [];
				$dummy_item['quantity'] = 0;
				$dummy_item['price'] = 0;
				$dummy_item['weight'] = 0;

				$orderItems[] = $dummy_item;
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

					if ($item['id'] != 'coupon-gift-dummy-item') {
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
					}

					if (isset($item['mod_price']) && $item['mod_price'] > 0) {
						$item['direct_discount'] = $item['original_price'] - $item['mod_price'];
					} else {
						$item['mod_price'] = null;
					}

					foreach ($adjustments_collection as $adjustment) {
						if (AdjustmentTypeProxy::IsCampaignDiscount($adjustment->type)) {
							$item['discount_id'] = $adjustment->getOrigin();
							$item['campaign_discount'] = $adjustment->getAmount();
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
						'number' 	=> $this->documentNumberGenerator->generateNumber(Prescription::class, 'PRESC_'),
						'info' 		=> '',
						'obs' 		=> ''
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

		if (Arr::get($data, 'cart_properties') !== null) {
			$cart_properties = Arr::get($data, 'cart_properties');

			if ($cart_properties->used_crossselling ?? false) {
				if (count($cart_properties->crossselling_products ?? []) > 0) {
					$cnps = Arr::map(function ($item) {
						return $item['product']->cnp;
					}, $orderItems);

					# if all the cnps are present in crossselling_products, mark the crossselling as completed
					$complete = array_reduce($cart_properties->crossselling_products ?? [], function ($carry, $item) use ($cnps) {
						return $carry && in_array($item, $cnps);
					}, true);

					if ($complete) {
						$order->has_bundle = 1;
						$order->save();
					}
				}
			}
		}

		event(new OrderWasCreated($order));
		event(new OrderStatusChanged($order, $order->status->value(), $order->status->value(), 'backoffice.order.events.was-created'));

		if (count($this->products_to_update) > 0) {
			event(new ProductUpdate($this->products_to_update));
		}

		return $order;
	}

	protected function createItems(Order $order, array $items)
	{
		foreach ($items as $item) {
			if ($item['product']->isBundleProduct()) {
				$this->createBundleItems($order, $item);

				//call update for the bundle itself, each bundle item will call an update
				$this->addProductUpdateEventIfNeeded($item);

				continue;
			}

			$this->createItem($order, $item);
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

			if ($item['name'] == '') {
				//Mandar o modelo do produto todo para um log info
				Log::info('Produto sem nome detectado. Dados do produto: ' . json_encode($product->toArray()));
			}

			$controlPercNumProductOffer = 0; //Como o desconto de percentagem e numerario é aplicado a cada produto quando tem a opção de oferta so pode ofrecer 1 vez

			foreach ($item['adjustments_collection'] as $adjustment) {
				if (AdjustmentTypeProxy::IsVisualSeparator($adjustment->type)) {
					if (
						$adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO() ||
						$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD_IGUAL()
					) {
						$product_off = Product::where('sku', $adjustment->getData('sku'))->first();

						$this->_createGrift($order, $item, $product_off, $adjustment, $adjustment->getData('quantity'));

						$item['campaign_discount'] = 0;
					} else if (
						$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD() ||
						$adjustment->type == AdjustmentTypeProxy::COUPON_FREE_PRODUCT()
					) {
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

						$item['campaign_discount'] = 0;
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

					if ($adjustment->type == AdjustmentTypeProxy::COUPON_PERC_NUM() && $coupon->offers_products == 1 && $controlPercNumProductOffer == 0) {
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

						$controlPercNumProductOffer = 1;
					}

					$this->createCoupon($coupon, $order);

					if ($coupon->isRegister()) {
						$userEnc = Auth::guard('web')->user();

						$userEnc->used_coupon = 1;
						$userEnc->save();
					}
				}
			}
		}

		if ($item['quantity'] != 0 && $item['id'] != 'coupon-gift-dummy-item') {
			if ($this->orderType == "backoffice") {
				$order->items()->updateOrCreate(['product_id' => $product->id, 'order_id' => $order->id], Arr::except($item, ['product', 'adjustments']));
			} else {
				$order->items()->create($item);
			}

			$this->addProductUpdateEventIfNeeded($item);
		}
	}

	protected function _createGrift(Order $order, array $item, ?Product $product_off, Modifier $adjustment, int | float $quantity)
	{
		if (null !== $product_off) {
			$free_item = array_replace([], $item); # Clonar array

			$free_item['product_id'] = $product_off->id;
			$free_item['cost_price'] = $product_off->cost_price;
			$free_item['original_price'] = $product_off->getPriceVat();
			$free_item['name'] = $product_off->name;
			$free_item['stock'] = $product_off->getStock();
			$free_item['weight'] = $product_off->weight();
			$free_item['product'] = $product_off;

			$free_item['quantity'] = $quantity;
			$free_item['price'] = 0;
			$free_item['store_discount'] = 0;
			$free_item['interval_discount'] = 0;
			$free_item['coupon_discount'] = 0;
			$free_item['direct_discount'] = 0;
			$free_item['campaign_discount'] = 0;

			if (
				$adjustment->type == AdjustmentTypeProxy::OFERTA_BARATO() ||
				$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD_IGUAL() ||
				$adjustment->type == AdjustmentTypeProxy::OFERTA_PROD()
			) {
				$free_item['campaign_discount'] = $adjustment->getAmount();
			} else if ($adjustment->type == AdjustmentTypeProxy::COUPON_FREE_PRODUCT()) {
				$free_item['coupon_discount'] = $adjustment->getAmount();
			}

			$order->items()->create(Arr::except($free_item, ['product', 'adjustments']));

			$this->addProductUpdateEventIfNeeded($free_item);
		}
	}

	protected function createBundleItems(Order $order, array $item)
	{
		// GET BUNDLE ADJUSTMENT
		$bundleAdjustmentConfig = collect($item['adjustments_collection'])->filter(function ($adjustment) {
			return $adjustment->type->equals(AdjustmentTypeProxy::BUNDLE_DISCOUNT());
		})->first();

		// GET COUPON ADJUSTMENT
		$couponAdjustmentConfig = collect($item['adjustments_collection'])->filter(function ($adjustment) {
			return $adjustment->type->equals(AdjustmentTypeProxy::COUPON_PERC_NUM());
		})->first();

		foreach ($item['product']->bundleItems as $bundleItem) {
			
			$_bundleAdjustmentConfig = collect($bundleAdjustmentConfig->getData('bundle_config'))->where('product_id', $bundleItem->product->id)->first();
			$_couponAdjustmentConfig = collect($couponAdjustmentConfig?->getData('bundle_items'))->where('product_id', $bundleItem->product->id)->first();

			$bundle_item = array_merge($item, [
				'product_type' 		=> $bundleItem->product->morphTypeName(),
				'product_id' 		=> $bundleItem->product->getId(),
				'product'			=> $bundleItem->product,
				'original_price' 	=> $bundleItem->product->getPriceVat(),
				'name' 				=> $bundleItem->product->getName(),
				'stock' 			=> $bundleItem->product->getStock(),
				'price' 			=> Utilities::RoundPrice($bundleItem->product->getPriceVat() - ($_bundleAdjustmentConfig['discount_amount'] ?? 0) - ($_couponAdjustmentConfig['discount_amount'] ?? 0)),
				'quantity' 			=> $bundleItem->quantity * $item['quantity'],
				'vat' 				=> $bundleItem->product->VAT_rate,
				'bundle_id' 		=> $item['product']->id,
				'bundle_discount' 	=> -($_bundleAdjustmentConfig['discount_amount']),
				'bundle_sku'		=> $item['product']->cnp,
				'coupon_discount' 	=> -($_couponAdjustmentConfig['discount_amount'] ?? 0)
			]);

			if ($bundle_item['quantity'] != 0) {
				$order->items()->create(Arr::except($bundle_item, ['product', 'adjustments']));

				$this->addProductUpdateEventIfNeeded($bundle_item);
			}
		}

		if ($couponAdjustmentConfig) {
			$this->createCoupon(Coupon::find($item['coupon_id']), $order);
		}
	}

	protected function addProductUpdateEventIfNeeded(array $item)
	{

		if ($item['product']->isUnlimitedAvailability() || $item['product']->isLimitedAvailability()) {
			return;
		}

		$updatedStock = $item['product']->getStock() - $item['quantity'];

		$productUpdateData = [
			'stock' => $updatedStock
		];

		if ($updatedStock <= 0) {
			$productUpdateData['state'] = ProductStateProxy::UNAVAILABLE()->value();
		}

		$this->products_to_update[] = [
			'product_id' => $item['product']->id,
			'data' => $productUpdateData
		];
	}

	protected function createCoupon(Coupon $coupon, Order $order)
	{
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
				'min_value_only_for_aplicable' => $coupon->min_value_only_for_aplicable,
				'coupon_id' => $coupon->id,
				'affiliate_user_id' => $coupon->affiliate_user_id,
				'commission_value' => $coupon->commission_value,
				'commission_value_type' => $coupon->commission_value_type,
				'commission_type' => $coupon->commission_type,
				'validate_domains' => $coupon->validate_domains,
				'domains' => $coupon->domains,
				'allows_medications' => $coupon->allows_medications,
				'offers_products' => $coupon->offers_products,
				'offer_product_min_purchase_value' => $coupon->offer_product_min_purchase_value
			]
		);
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
