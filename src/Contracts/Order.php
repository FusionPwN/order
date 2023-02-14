<?php

declare(strict_types=1);
/**
 * Contains the Order interface.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-11-27
 *
 */

namespace Vanilo\Order\Contracts;

use Illuminate\Support\Collection;
use Traversable;

interface Order
{
    public function getNumber(): ?string;

    public function getStatus(): OrderStatus;

    public function getBillpayer(): ?Collection;

    public function getShippingAddress(): ?Collection;

    public function getItems(): Traversable;

    /**
     * Returns the final total of the Order
     *
     * @return float
     */
    public function total();

	public function itemsTotal(): float;
}
