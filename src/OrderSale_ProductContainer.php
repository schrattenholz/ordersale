<?php

namespace Schrattenholz\OrderSale;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\Order\Preis;
use Silverstripe\ORM\DataObject;
use Silverstripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\ValidationException;
class OrderSale_ProductContainer extends DataExtension{
	
	public function onBeforeWrite(){
		if($this->getOwner()->ProductID!=0 && $this->getOwner()->PriceBlockElementID!=0 & $this->getOwner()->Quantity<1)
		{
			 throw new ValidationException('Bitte geben Sie eine Mengenangabe ein.');
		}

		if($this->getOwner()->Product()->InPreSale && $this->getOwner()->ProductID!=0 && $this->getOwner()->PriceBlockElementID!=0){
			$productContainer=OrderProfileFeature_ProductContainer::get()->filter(
				[
					'ProductID'=>$this->getOwner()->ProductID,
					'PriceBlockElementID'=>$this->getOwner()->PriceBlockElementID,
					'Created:GreaterThanOrEqual'=>strtotime($this->getOwner()->Product()->PreSaleStart)
				]
			)->exclude('ID',$this->getOwner()->ID);
			$totalRegisteredQuantity=0;
			foreach($productContainer as $pC){
				$totalRegisteredQuantity=$totalRegisteredQuantity+$pC->Quantity;
			}
			if($totalRegisteredQuantity+$this->getOwner()->getField('Quantity')>$this->getOwner()->PriceBlockElement()->Quantity){
				$this->getOwner()->Quantity=($totalRegisteredQuantity+$this->getOwner()->getField('Quantity'))-$this->getOwner()->Quantity;
			}
		}
		parent::onBeforeWrite();
	}
	public function onAfterWrite(){
		if($this->getOwner()->Product()->InPreSale && $this->getOwner()->ClientOrderID!=0){
			//$this->getOwner()->Product()->AfterMakeOrder($this->getOwner()->ClientOrder());
		}
		parent::onAfterWrite();
	}
}