<?php

declare(strict_types=1);
/**
 * Contains the OrderItem model class.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-11-27
 *
 */

namespace Vanilo\Order\Models;

use App\Models\Traits\ProductItem;
use Illuminate\Database\Eloquent\Model;
use Vanilo\Adjustments\Contracts\Adjustable;
use Vanilo\Adjustments\Support\HasAdjustmentsViaRelation;
use Vanilo\Adjustments\Support\RecalculatesAdjustments;
use Vanilo\Cart\Traits\CheckoutItemFunctions;
use Vanilo\Order\Contracts\OrderItem as OrderItemContract;

class OrderItem extends Model implements OrderItemContract, Adjustable
{
	use HasAdjustmentsViaRelation;
	use RecalculatesAdjustments;
	use ProductItem;
	use CheckoutItemFunctions;

	protected $guarded = ['id', 'created_at', 'updated_at'];

	protected $casts = [
		'properties' => 'object'
	];

	protected $appends = ['prices'];

	public function order()
	{
		return $this->belongsTo(OrderProxy::modelClass());
	}

	public function product()
	{
		return $this->morphTo();
	}

	/**
	 * Property accessor alias to the total() method
	 *
	 * @return float
	 */
	public function getTotalAttribute()
	{
		return $this->total();
	}

	/**
	 * Property accessor alias to the prices() method
	 *
	 * @return float
	 */
	public function getPricesAttribute()
	{
		return $this->prices;
	}
}
