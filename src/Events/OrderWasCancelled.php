<?php

declare(strict_types=1);
/**
 * Contains the OrderWasCancelled class.
 *
 * @copyright   Copyright (c) 2018 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2018-11-10
 *
 */

namespace Vanilo\Order\Events;

use Vanilo\Order\Contracts\Order;

class OrderWasCancelled extends BaseOrderEvent
{
    public bool $auto;
    public ?string $cancellationReason;

    public function __construct(Order $order, bool $auto = false, ?string $cancellationReason = null)
    {
        parent::__construct($order);

        $this->auto = $auto;
        $this->cancellationReason = $cancellationReason;
    }

}
