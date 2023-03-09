<?php

namespace Schrattenholz\OrderSale;


use Silverstripe\ORM\DataExtension;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\View\ArrayData;
use Schrattenholz\Order\Basket;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;

//Debugging
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;

class OrderSale_PreisExtension extends DataExtension{
	private static $db=[
		'Inventory'=>'Int',
		'InfiniteInventory'=>'Boolean(1)',
		'BlockedQuantity'=>'Int',
		'InPreSale' => 'Boolean',
		'PreSaleInventory'=>'Int',
		'PreSaleStartInventory'=>'Int',
		'PreSaleStart'=>'Date',
		'PreSaleEnd'=>'Date',
		'ResetPreSale'=>'Boolean',
		'InSale'=>'Boolean',
		'SaleDiscount'=>'Decimal(6,2)',
		'SaleFinish'=>'Date'
	];
	public function getSoldPercentage(){
		//Injector::inst()->get(LoggerInterface::class)->error(' CurrentInventory'.$this->owner->FreeQuantity($this->getPreisDetails())." startIn=".$this->getPreSaleStatus()->StartInventory);
		if($this->getPreSaleStatus()->StartInventory>0){
			return 100-($this->getPreSaleStatus()->CurrentInventory/$this->getPreSaleStatus()->StartInventory*100); 
		}else{
			
			return 0;
		}
	}
	public function Test(){
			return "muh";
	}
	
	public function getPreSaleMode(){
		if($this->owner->InPreSale){
			if($this->owner->PreSaleEnd){
				return "presale";
			}else{
				return "openpresale";
			}			
		}else{
			return false;
		}		
	}
	public function CurrentInventory(){
		return $this->getPreSaleStatus()->CurrentInventory;
	}
	public function SoldInventory(){
		return $this->getPreSaleStatus()->StartInventory-$this->getPreSaleStatus()->CurrentInventory;
		
	}
	public function SoldRatioInventory(){

		if($this->owner->InPreSale){
			return $this->SoldInventory()." / ".$this->owner->PreSaleStartInventory;
		}else{
			
			
		}
	}
	public function getPreSaleStatus(){
		
		if($this->owner->InPreSale){
			return new ArrayData(["StartInventory"=>$this->owner->PreSaleStartInventory,"CurrentInventory"=>$this->owner->FreeQuantity($this->getPreisDetails())['QuantityLeft']]);	 
		}else{
			return false;
		}
	}
	public function getPreisDetails(){
		$pd=array();
		$pd['variant01']=$this->owner->ID;
		$pd['productID']=$this->owner->ProductID;			
		return $pd;
	}
	public function updateCMSFields(FieldList $fields){
			$infiniteInventory=new CheckboxField("InfiniteInventory","Das Produkt hat einen unendlichen Bestand.");
			$fields->addFieldToTab('Root.Main',$infiniteInventory,'OrderCustomerGroups_Preis');
			$num=new NumericField("Inventory","Stückzahl dieses Produkt");
			$fields->addFieldToTab('Root.Main',$num,'InfiniteInventory');
		
		
		//Vorverkauf
		
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('InPreSale','Vorverkauf'));
		$preSaleInventory=new NumericField("PreSaleInventory","Standardmenge für den Vorverkauf(Wenn der Vorverkauf per Stappelverarbeitung gestartet wird, bekommt das Produkt diesen Bestand zugewiesen.");
		$preSaleInventory->setLocale("DE_De");
		$preSaleStartInventory=new NumericField("PreSaleStartInventory","Anfangsbestand des Vorverkauf");
		$preSaleStartInventory->setLocale("DE_De");
		$fields->addFieldToTab('Root.Verkaufsaktionen',$preSaleInventory);
		$fields->addFieldToTab('Root.Verkaufsaktionen',$preSaleStartInventory);
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('ResetPreSale','Vorverkauf zurücksetzen'));
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('PreSaleStart','Start des Vorverkauf'));
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('PreSaleEnd','Ende des Vorverkauf'));
		//$fields->addFieldToTab('Root.Verkaufsaktionen',new LiteralField('Spacer','</hr>'));
		//Rabatt-Aktion
		/*
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('InSale','Rabatt-Aktion'));
		$saleDiscount=new NumericField("SaleDiscount","Preisrabatt in Prozent");
		$saleDiscount->setLocale("DE_De");
		$saleDiscount->setScale(2);
		$fields->addFieldToTab('Root.Verkaufsaktionen',$saleDiscount);
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('SaleFinish','Ende der Rabatt-Aktion'));
		*/
		
	}
	public function onBeforeWrite(){
		parent::onBeforeWrite();
		if($this->owner->PreSaleStartInventory==0 && $this->owner->InPreSale){
			$this->owner->PreSaleStartInventory=$this->owner->PreSaleInventory;
		}
	}
	public function onAfterWrite(){
		parent::onAfterWrite();
	}
	public function IsAvailable(){
		if($this->owner->InfiniteInventory || $this->owner->Inventory>0){
			return true;
		}else{
			return false;
		}
	}
	public function FreePortionalQuantity(){
		if($this->owner->InfiniteInventory){
			$fq=10000000;
		}else{
			$pCs=$this->ReservedProductContainers();
			$totalQuantity=0;
			foreach($pCs as $pC){
				$totalQuantity=$totalQuantity+$pC->Inventory;
			}
			$fq=(($this->getOwner()->Inventory)-($totalQuantity));
			if($fq<0){
				$fq=0;
			}
		}
		return $fq;
	}
	public function ReservedProductContainers(){
		$now = date("Y-m-d H:i:s");
		$timestamp = "2016-04-20 00:37:15";
		$start_date = date($now);
		$expires = strtotime('-11 minute', strtotime($now));
		$date_diff=($expires-strtotime($now)) / 86400;

		$product=$this->owner;

		if(!$product->InfiniteInventory){
			//Wenn das Produkt einen Warenbestand benutzt, muss die Anzahl der Reservierungen ermittelt werden 
			
			$reservedQuantity=0;
			$tmpPc=new ArrayList();
			foreach(Basket::get()->filter(['LastEdited:GreaterThanOrEqual'=>$expires]) as $basket){
				if($product->ClassName=="Schrattenholz\\Order\\Preis"){
					//Varianten Produkt
					$pCs=$basket->ProductContainers()->filter([
						'ProductID'=>$pd['productID'],
						'PriceBlockElementID'=>$pd['variant01']
					]);
				}else{
					//Normles Produkt
					$pCs=$basket->ProductContainers()->filter([
						'ProductID'=>$pd['productID']
					]);
				}
				foreach($pCs as $pc){
					$reservedQuantity+=$pc->Quantity;
					$tmpPc->push($pc);
				}
			}
			$productContainer=$tmpPc;
			
		}else{
			$productContainer=false;
		}
		return $productContainer;
	}
	public function ActivePreSale(){
		$heute = strtotime(date("Y-m-d"));
		//Injector::inst()->get(LoggerInterface::class)->error($heute.' activepresale presalestart='.strtotime($this->owner->PreSaleStart));
		if($this->owner->PreSaleEnd){
			//Vorverkauf mit festem Enddatum
			if($this->owner->InPreSale && $heute >= strtotime($this->owner->PreSaleStart) && $heute <= strtotime($this->owner->PreSaleEnd)){
				return true;
			}else{
				return false;
			}
		}else{
			//Abverkauf bis alles weg ist,... keine Enddatum
			if($this->owner->InPreSale && $heute >= strtotime($this->owner->PreSaleStart)){
				return true;
			}else{
				return false;
			}
		}
	}
	public function IsActive(){
		
		$orderCustomerGroup=$this->owner->OrderCustomerGroups()->filter('GroupID',$this->getOwner()->CurrentGroup()->ID)->First();
		
		if($orderCustomerGroup && $this->IsAvailable()){
			//if($this->owner->ActivePreSale() || $this->owner->InPreSale==false){
				$relPreis=OrderCustomerGroups_Preis::get()->filter('PreisID',$this->owner->ID)->filter('OrderCustomerGroupID',$orderCustomerGroup->ID)->First();
				return $relPreis->Active;

			/*}else{
				return false;
			}*/
		}else{
			return false;
		}
	}
	public function checkSoldQuantity(){
		
		$quantity=0;
		$pCs=OrderProfileFeature_ProductContainer::get()->filter(
		[
			'PriceBlockElementID'=>$this->getOwner()->ID,
			'ClientOrderID:GreaterThan'=>0,
			'Created:GreaterThanOrEqual'=>strtotime($this->getOwner()->Product()->PreSaleStart)
		]);
		foreach($pCs as $pC){
			$quantity=$quantity+$pC->Quantity;
		}
		
		$this->getOwner()->SoldQuantity=$quantity;
		$this->getOwner()->write();
		if($quantity==$this->getOwner()->Quantity){
			
			return 'salefinished';
		}else{
			return 'insale';
		}
	}
}
