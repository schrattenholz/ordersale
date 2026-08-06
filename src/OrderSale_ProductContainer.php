<?php

namespace Schrattenholz\OrderSale;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\Order\Preis;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator as RequiredFields;
use SilverStripe\ORM\ValidationException;
class OrderSale_ProductContainer extends Extension{

	// Haelt fest, aus welcher Vorverkaufs-Kampagne diese Position stammt.
	// Wird beim Anlegen im Warenkorb gesetzt (siehe stampPreSale()) und danach
	// nicht mehr veraendert -- damit bleibt die Herkunft auch dann erhalten,
	// wenn der Vorverkauf spaeter beendet oder neu gestartet wird.
	private static $has_one=[
		'PreSale'=>PreSale::class
	];

	private static $summary_fields=[
		'PreSale.Title'=>'Vorverkauf'
	];

	/**
	 * Ordnet die Position der derzeit aktiven Kampagne ihrer Warengruppe zu.
	 *
	 * Bewusst schon beim Anlegen im Warenkorb und nicht erst beim Kauf: nur so
	 * zaehlen auch die reservierten Mengen zur Kampagne, nicht bloss die
	 * verkauften. Einmal gesetzt, bleibt der Wert stehen.
	 */
	private function stampPreSale(){
		$owner=$this->getOwner();
		if($owner->PreSaleID || !$owner->ProductID || !$owner->PriceBlockElementID){
			return;
		}
		// Der Vorverkaufs-Zustand haengt an der Variante, nicht am Produkt --
		// Product.InPreSale bleibt in der Praxis auf 0, waehrend die einzelnen
		// Preis-Zeilen InPreSale=1 tragen.
		$preis=$owner->PriceBlockElement();
		if(!$preis || !$preis->exists() || !$preis->InPreSale || $preis->NotInPresale){
			return;
		}
		$product=$owner->Product();
		if(!$product || !$product->exists()){
			return;
		}
		$preSale=PreSale::activeFor($product->ParentID);
		if($preSale){
			$owner->PreSaleID=$preSale->ID;
		}
	}

	public function onBeforeWrite(){
		$this->stampPreSale();
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
	}
	public function onAfterWrite(){
		if($this->getOwner()->Product()->InPreSale && $this->getOwner()->ClientOrderID!=0){
			//$this->getOwner()->Product()->AfterMakeOrder($this->getOwner()->ClientOrder());
		}
	}
}