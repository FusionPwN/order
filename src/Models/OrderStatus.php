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
	const __DEFAULT 			= self::PENDING;
	const IN_CREATION 			= 'em_criacao';
	const PENDING 				= 'pendente';
	const PAID 					= 'paga';
	const DISPATCHED 			= 'expedida';
	const ON_BILLING 			= 'em_faturacao';
	const BILLED 				= 'faturada';
	const COMPLETED 			= 'concluida';
	const CANCELLED 			= 'cancelada';
	const AWAITS_CONFIRMATION 	= 'aguarda_confirmacao';
	const AWAITS_PAYMENT 		= 'aguarda_pagamento';

	// $labels static property needs to be defined
	public static $labels = [];

	protected static $visibility = [
		self::IN_CREATION			=> false,
		self::PENDING 				=> true,
		self::PAID					=> true,
		self::CANCELLED 			=> true,
		self::COMPLETED 			=> true,
		self::DISPATCHED 			=> true,
		self::AWAITS_CONFIRMATION 	=> false,
		self::AWAITS_PAYMENT 		=> false,
		self::ON_BILLING 			=> true,
		self::BILLED 				=> true,
	];

	public static function choices()
	{
		$result = [];
		$choices = parent::choices();

		foreach ($choices as $key => $value) {
			if (self::$visibility[$key]) {
				$result[$key] = $value;
			}
		}

		return $result;
	}

	protected static $openStatuses 			= [self::IN_CREATION, self::AWAITS_CONFIRMATION, self::PENDING, self::AWAITS_PAYMENT, self::PAID, self::DISPATCHED, self::ON_BILLING];
	protected static $closedStatuses 		= [self::CANCELLED, self::COMPLETED];
	protected static $paidStatuses 			= [self::PAID, self::DISPATCHED, self::ON_BILLING, self::COMPLETED];
	protected static $stockStatuses 		= [self::IN_CREATION, self::AWAITS_CONFIRMATION, self::PENDING, self::AWAITS_PAYMENT, self::PAID, self::DISPATCHED, self::ON_BILLING];
	protected static $editableStatuses 		= [self::IN_CREATION, self::AWAITS_CONFIRMATION];
	protected static $payableStatuses 		= [self::PENDING, self::AWAITS_PAYMENT];

	# para a app
	protected static $statusColors = [
		self::IN_CREATION			=> '#FF6700',
		self::PENDING 				=> '#de972d',
		self::PAID					=> '#3399d8',
		self::CANCELLED 			=> '#dd302a',
		self::COMPLETED 			=> '#349ed2',
		self::DISPATCHED 			=> '#85c62c',
		self::AWAITS_CONFIRMATION 	=> '#FF6700',
		self::AWAITS_PAYMENT 		=> '#FF6700',
	];

	protected static $statusIcons = [
		self::IN_CREATION			=> 'fa.exclamationCircle',
		self::PENDING 				=> 'fa.clock',
		self::PAID					=> 'fa.moneyBillAlt',
		self::CANCELLED 			=> 'fa.ban',
		self::COMPLETED 			=> 'fa.calendarCheck',
		self::DISPATCHED 			=> 'fa.shippingFast',
		self::AWAITS_CONFIRMATION 	=> 'fa.exclamationCircle',
		self::AWAITS_PAYMENT 		=> 'fa.exclamationCircle',
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

	public function isPayable(): bool
	{
		return in_array($this->value, static::$payableStatuses);
	}

	public function isInCreation(): bool
	{
		return $this->value === self::IN_CREATION;
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

	public static function getPayableStatuses(): array
	{
		return static::$payableStatuses;
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
			self::IN_CREATION 			=> __('backoffice.order.in_creation'),
			self::PENDING     			=> __('backoffice.order.pending'),
			self::COMPLETED   			=> __('backoffice.order.completed'),
			self::CANCELLED   			=> __('backoffice.order.cancelled'),
			self::PAID        			=> __('backoffice.order.paid'),
			self::DISPATCHED  			=> __('backoffice.order.dispatched'),
			self::ON_BILLING  			=> __('backoffice.order.on_billing'),
			self::BILLED      			=> __('backoffice.order.billed'),
			self::AWAITS_CONFIRMATION 	=> __('backoffice.order.awaits-confirmation'),
			self::AWAITS_PAYMENT 		=> __('backoffice.order.awaits-payment'),
		];
	}
}
