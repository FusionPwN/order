<?php

declare(strict_types=1);
/**
 * Contains the OrderStatus enum class.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-11-27
 *
 */

namespace Vanilo\Order\Models;

use Konekt\Enum\Enum;
use Vanilo\Order\Contracts\OrderStatus as OrderStatusContract;

class OrderStatus extends Enum implements OrderStatusContract
{
	const __DEFAULT = self::PENDING;

	const IN_CREATION = 'em_criacao';

	/**
	 * Pending orders are brand new orders that have not been processed yet.
	 */
	const PENDING = 'pendente';

	/**
	 * Payed orders are brand new orders that have not been processed yet.
	 */
	const PAID = 'paga';

	/**
	 * Delivered orders are brand new orders that have not been processed yet.
	 */
	const DISPATCHED = 'expedida';

	/**
	 * Delivered orders are brand new orders that have not been processed yet.
	 */
	const ON_BILLING = 'em_faturacao';

	/**
	 * Delivered orders are brand new orders that have not been processed yet.
	 */
	const BILLED = 'faturada';

	/**
	 * Orders fulfilled completely.
	 */
	const COMPLETED = 'concluida';

	/**
	 * Order that has been cancelled.
	 */
	const CANCELLED = 'cancelada';

	// $labels static property needs to be defined
	public static $labels = [];

	protected static $openStatuses = [self::IN_CREATION, self::PENDING, self::PAID, self::DISPATCHED, self::ON_BILLING];
	protected static $closedStatuses = [self::CANCELLED, self::COMPLETED];
	protected static $paidStatuses = [self::PAID, self::DISPATCHED, self::ON_BILLING, self::COMPLETED];
	protected static $stockStatuses = [self::IN_CREATION, self::PENDING, self::PAID, self::ON_BILLING];
	protected static $editableStatuses = [self::IN_CREATION, self::PENDING, self::PAID];

	# para a app
	protected static $statusColors = [
		self::PENDING 		=> '#de972d',
		self::PAID			=> '#3399d8',
		self::CANCELLED 	=> '#dd302a',
		self::COMPLETED 	=> '#349ed2',
		self::DISPATCHED 	=> '#85c62c',
	];

	protected static $statusIcons = [
		self::PENDING 		=> 'fa.clock',
		self::PAID			=> 'fa.moneyBillAlt',
		self::CANCELLED 	=> 'fa.ban',
		self::COMPLETED 	=> 'fa.calendarCheck',
		self::DISPATCHED 	=> 'fa.shippingFast',
	];

	public function isOpen(): bool
	{
		return in_array($this->value, static::$openStatuses);
	}

	public function isClosed(): bool
	{
		return in_array($this->value, static::$closedStatuses);
	}

	public function isEditable(): bool
	{
		return in_array($this->value, static::$editableStatuses);
	}

	public static function getOpenStatuses(): array
	{
		return static::$openStatuses;
	}

	public static function getClosedStatuses(): array
	{
		return static::$closedStatuses;
	}

	public static function getPaidStatuses(): array
	{
		return static::$paidStatuses;
	}

	public static function getStockStatuses(): array
	{
		return static::$stockStatuses;
	}

	public static function getEditableStatuses(): array
	{
		return static::$editableStatuses;
	}

	public static function getStatusLabel(string $status): string
	{
		return self::$labels[$status];
	}

	public static function getStatusColor(string $status): string
	{
		return self::$statusColors[$status];
	}

	public static function getStatusIcon(string $status): string
	{
		return self::$statusIcons[$status];
	}

	protected static function boot()
	{
		static::$labels = [
			self::IN_CREATION => __('backoffice.order.in_creation'),
			self::PENDING     => __('backoffice.order.pending'),
			self::COMPLETED   => __('backoffice.order.completed'),
			self::CANCELLED   => __('backoffice.order.cancelled'),
			self::PAID        => __('backoffice.order.paid'),
			self::DISPATCHED  => __('backoffice.order.dispatched'),
			self::ON_BILLING  => __('backoffice.order.on_billing'),
			self::BILLED      => __('backoffice.order.billed'),
		];
	}
}
