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
use Illuminate\Support\Facades\Cache;

class OrderStatus extends Enum implements OrderStatusContract
{
	const __DEFAULT 					= self::PENDING;
	const IN_CREATION 						= 'em_criacao';
	const AWAITS_CONFIRMATION 				= 'aguarda_confirmacao';
	const AWAITS_PAYMENT 					= 'aguarda_pagamento';
	const PENDING 							= 'pendente';
	const PAID 								= 'paga';
	const IN_WAREHOUSE_PREPARATION 			= 'em_preparacao_armazem';
	const IN_PREPARATION_PHARMACY_STORE 	= 'em_preparacao_farmacia_loja';
	const PROCESSING 						= 'em_processamento';
	const ON_BILLING 						= 'em_faturacao';
	const BILLED 							= 'faturada';
	const DISPATCHED 						= 'expedida';
	const READY_FOR_PICKUP					= 'pronta_para_levantamento';
	const COMPLETED 						= 'concluida';
	const REFUNDING 						= 'em_devolucao';
	const REFUNDED 							= 'devolvido';
	const CANCELLED 						= 'cancelada';
	

	// $labels static property needs to be defined
	public static $labels = [];

	protected static $visibility = [
		self::IN_CREATION						=> true,
		self::AWAITS_CONFIRMATION 				=> true,
		self::AWAITS_PAYMENT 					=> true,
		self::PENDING 							=> true,
		self::PAID								=> true,
		self::CANCELLED 						=> true,
		self::COMPLETED 						=> true,
		self::DISPATCHED 						=> true,
		self::ON_BILLING 						=> true,
		self::BILLED 							=> true,
		self::IN_WAREHOUSE_PREPARATION 			=> true,
		self::IN_PREPARATION_PHARMACY_STORE 	=> true,
		self::READY_FOR_PICKUP 					=> true,
		self::PROCESSING 						=> true,
		self::REFUNDING 						=> true,
		self::REFUNDED 							=> true,
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

	protected static $openStatuses 			= [self::IN_CREATION, self::AWAITS_CONFIRMATION, self::PENDING, self::AWAITS_PAYMENT, self::PAID, self::DISPATCHED, self::ON_BILLING, self::IN_WAREHOUSE_PREPARATION, self::PROCESSING,self::REFUNDING,self::IN_PREPARATION_PHARMACY_STORE,self::READY_FOR_PICKUP];
	protected static $closedStatuses 		= [self::CANCELLED, self::COMPLETED, self::REFUNDED ];
	protected static $paidStatuses 			= [self::PAID, self::DISPATCHED, self::ON_BILLING, self::BILLED, self::COMPLETED, self::IN_WAREHOUSE_PREPARATION, self::PROCESSING,self::REFUNDING,self::IN_PREPARATION_PHARMACY_STORE,self::READY_FOR_PICKUP];
	protected static $stockStatuses 		= [self::IN_CREATION, self::AWAITS_CONFIRMATION, self::PENDING, self::AWAITS_PAYMENT, self::PAID, self::DISPATCHED, self::ON_BILLING, self::IN_WAREHOUSE_PREPARATION, self::PROCESSING,self::IN_PREPARATION_PHARMACY_STORE ];
	protected static $editableStatuses 		= [self::IN_CREATION, self::AWAITS_CONFIRMATION];
	protected static $payableStatuses 		= [self::PENDING, self::AWAITS_PAYMENT];
	protected static $billableStatuses 		= [self::ON_BILLING];

	# para a app
	protected static $statusColors = [
		self::IN_CREATION				=> '#FF6700',
		self::PENDING 					=> '#de972d',
		self::PAID						=> '#3399d8',
		self::CANCELLED 				=> '#dd302a',
		self::COMPLETED 				=> '#349ed2',
		self::DISPATCHED 				=> '#85c62c',
		self::AWAITS_CONFIRMATION 		=> '#FF6700',
		self::AWAITS_PAYMENT 			=> '#FF6700',
		self::IN_WAREHOUSE_PREPARATION 	=> '#FFD700',
		self::PROCESSING 				=> '#FFD700',
		self::REFUNDING 				=> '#FFA500',
		self::REFUNDED 					=> '#FF0000',
	];

	protected static $statusIcons = [
		self::IN_CREATION				=> 'fa.exclamationCircle',
		self::PENDING 					=> 'fa.clock',
		self::PAID						=> 'fa.moneyBillAlt',
		self::CANCELLED 				=> 'fa.ban',
		self::COMPLETED 				=> 'fa.calendarCheck',
		self::DISPATCHED 				=> 'fa.shippingFast',
		self::AWAITS_CONFIRMATION 		=> 'fa.exclamationCircle',
		self::AWAITS_PAYMENT 			=> 'fa.exclamationCircle',
		self::IN_WAREHOUSE_PREPARATION 	=> 'fa.warehouse',
		self::PROCESSING 				=> 'fa.spinner',
		self::REFUNDING 				=> 'fa.undo',
		self::REFUNDED 					=> 'fa.undoAlt',
	];

	public function __construct($value = null)
	{
		parent::__construct($value);

		if(Cache::get('settings.products.reserved-stock-states') !== null && Cache::get('settings.products.reserved-stock-states') != ""){
			static::$stockStatuses = explode(',', Cache::get('settings.products.reserved-stock-states'));
		}
	}

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

	public function isBillable(): bool
	{
		return $this->value === self::ON_BILLING;
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
		self::boot();
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

	public static function getBillableStatuses(): array
	{
		return static::$billableStatuses;
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

		if(Cache::get('settings.products.reserved-stock-states') !== null && Cache::get('settings.products.reserved-stock-states') != ""){
			static::$stockStatuses = explode(',', Cache::get('settings.products.reserved-stock-states'));
		}

		static::$labels = [
			self::IN_CREATION 						=> __('backoffice.order.in_creation'),
			self::AWAITS_CONFIRMATION 				=> __('backoffice.order.awaits-confirmation'),
			self::AWAITS_PAYMENT 					=> __('backoffice.order.awaits-payment'),
			self::PENDING     						=> __('backoffice.order.pending'),
			self::CANCELLED   						=> __('backoffice.order.cancelled'),
			self::PAID        						=> __('backoffice.order.paid'),
			self::IN_WAREHOUSE_PREPARATION 			=> __('backoffice.order.in-warehouse-preparation'),
			self::IN_PREPARATION_PHARMACY_STORE 	=> __('backoffice.order.In preparation Pharmacy/Store'),
			self::PROCESSING 						=> __('backoffice.order.processing'),
			self::ON_BILLING  						=> __('backoffice.order.on_billing'),
			self::BILLED      						=> __('backoffice.order.billed'),
			self::DISPATCHED  						=> __('backoffice.order.dispatched'),
			self::READY_FOR_PICKUP 					=> __('backoffice.order.Ready for pickup'),
			self::REFUNDING 						=> __('backoffice.order.refunding'),
			self::REFUNDED 							=> __('backoffice.order.refunded'),
			self::COMPLETED   						=> __('backoffice.order.completed'),
		];
	}
}
