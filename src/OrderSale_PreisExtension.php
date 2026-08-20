<?php

namespace Schrattenholz\OrderSale;


use SilverStripe\Core\Extension;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Model\ArrayData;
use Schrattenholz\Order\Basket;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;

//Debugging
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;

class OrderSale_PreisExtension extends Extension{
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
		'SaleFinish'=>'Date',
		'PreSaleEndPercentage'=>'Enum("25,50,75,100","100")'
	];
	public function getSoldPercentage(){
		//Injector::inst()->get(LoggerInterface::class)->error(' CurrentInventory'.$this->owner->FreeQuantity($this->getPreisDetails())." startIn=".$this->getPreSaleStatus()->StartInventory);
		$status=$this->getPreSaleStatus();
		// FreeQuantity()'s "QuantityLeft" (which becomes CurrentInventory here) is intentionally the
		// string "Auf Lager" for products with InfiniteInventory=true -- that's relied on elsewhere
		// (templates display it directly, OrderSale_OrderExtension defensively floatval()s it). A
		// percentage is meaningless for infinite stock anyway, so just skip the calculation here
		// rather than trying to divide by/with a non-numeric value.
		if($status && $status->StartInventory>0 && is_numeric($status->CurrentInventory)){
			return 100-($status->CurrentInventory/$status->StartInventory*100);
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

	/**
	 * Bis einschliesslich dieser Menge gilt der Bestand als knapp.
	 * Entspricht der Staffelung im Badge-Template: >2 komfortabel, 1..2 knapp, 0 weg.
	 */
	private const LOW_STOCK_THRESHOLD = 2;

	/**
	 * Freie Menge dieser Variante -- ohne Umweg ueber FreeQuantity().
	 *
	 * Rechnet bewusst dasselbe wie FreeQuantity()['QuantityLeft'], vermeidet
	 * aber dessen getBasket()-Aufruf. Der dient dort ausschliesslich dazu,
	 * ClientsQuantity zu ermitteln (was der aktuelle Besucher selbst im Korb
	 * hat) und geht in QuantityLeft gar nicht ein -- er zwingt die Methode
	 * aber in einen HTTP-Request-Kontext und laesst sie im CLI/Task mit
	 * "Too few arguments to HTTPRequest::__construct()" scheitern.
	 *
	 * Ohne diesen Umweg ist der Zustand ueberall abfragbar: Template, App-API,
	 * BuildTask, Test.
	 */
	public function AvailableQuantity(){
		$preis=$this->owner;
		if($preis->InPreSale){
			$left=(int)$preis->PreSaleStartInventory-(int)$preis->PreSale_SoldAndReserved()->Total;
		}else{
			$left=(int)$preis->Inventory-(int)$preis->Reserved();
		}
		return $left>0 ? $left : 0;
	}

	/**
	 * Verfuegbarkeits-Zustand einer Variante -- eine Wahrheit fuer Badge,
	 * strukturierte Daten und App/API.
	 *
	 * Bewusst getrennt in Zustand (hier) und Darstellung (Template): das
	 * Badge-Markup mischte bisher Zustand, deutsche Texte und CSS-Klassen.
	 * Nur der Zustand ist wiederverwendbar.
	 *
	 * State: soldout | infinite | available | low | none
	 *        | presale | presale-open | presale-done
	 */
	public function AvailabilityInfo(){
		$preis=$this->owner;

		// OutOfStock haengt am Produkt, nicht an der Variante: es ist der
		// Sammelschalter, mit dem sich ein ganzes Produkt abschalten laesst,
		// ohne jede Variante einzeln anzufassen. Deshalb sticht es alles andere.
		$product=$preis->Product();
		if($product && $product->exists() && $product->OutOfStock){
			return new ArrayData(['State'=>'soldout','Quantity'=>0,'IsOrderable'=>false]);
		}

		// Unbegrenzter Bestand -- die Menge ist hier bedeutungslos.
		if($preis->InfiniteInventory){
			return new ArrayData(['State'=>'infinite','Quantity'=>null,'IsOrderable'=>true]);
		}

		$left=$preis->AvailableQuantity();

		$mode=$preis->getPreSaleMode(); // presale | openpresale | false
		if($mode){
			if($left<=0){
				return new ArrayData(['State'=>'presale-done','Quantity'=>0,'IsOrderable'=>false]);
			}
			return new ArrayData([
				'State'=>$mode=="presale" ? 'presale' : 'presale-open',
				'Quantity'=>$left,
				'IsOrderable'=>true
			]);
		}

		if($left<=0){
			return new ArrayData(['State'=>'none','Quantity'=>0,'IsOrderable'=>false]);
		}
		return new ArrayData([
			'State'=>$left>self::LOW_STOCK_THRESHOLD ? 'available' : 'low',
			'Quantity'=>$left,
			'IsOrderable'=>true
		]);
	}

	/**
	 * Zustand fuer schema.org -- BEWUSST nicht aus AvailabilityInfo() abgeleitet.
	 *
	 * Grund: FreeQuantity() zieht Reservierungen ab, also alles, was gerade in
	 * irgendeinem Warenkorb der letzten 11 Minuten liegt (Reserved()). Fuers
	 * Badge im Shop ist das genau richtig. Fuer strukturierte Daten waere es
	 * schaedlich: die Verfuegbarkeit im ausgelieferten HTML haenge davon ab,
	 * wer zufaellig in derselben Minute etwas im Korb hat -- ein Crawler saehe
	 * ein flackerndes Signal. Hier zaehlt deshalb der Lagerbestand ohne
	 * Reservierungen.
	 */
	public function SchemaOrgAvailability(){
		$preis=$this->owner;
		$product=$preis->Product();

		if($product && $product->exists() && $product->OutOfStock){
			return 'https://schema.org/OutOfStock';
		}
		if($preis->InfiniteInventory){
			return 'https://schema.org/InStock';
		}
		if($preis->InPreSale){
			// PreOrder trifft es genauer als InStock -- die Ware existiert,
			// ist aber noch nicht abholbereit.
			$sold=$preis->PreSale_SoldAndReserved()->Sold;
			return ((int)$preis->PreSaleStartInventory-(int)$sold)>0
				? 'https://schema.org/PreOrder'
				: 'https://schema.org/SoldOut';
		}
		return ((int)$preis->Inventory)>0
			? 'https://schema.org/InStock'
			: 'https://schema.org/OutOfStock';
	}
	public function CurrentInventory(){
		return $this->getPreSaleStatus()->CurrentInventory;
	}

	public function SoldRatioInventory(){

		if($this->owner->InPreSale){
			return $this->PreSale_SoldAndReserved()->Sold."(+".$this->PreSale_SoldAndReserved()->Reserved.")"." / ".$this->owner->PreSaleStartInventory;
		}else{
			
			
		}
	}
	public function getPreSaleStatus(){
		Injector::inst()->get(LoggerInterface::class)->error(" getPreSaleStatus productID".$this->getPreisDetails()['productID']." variant01=".$this->getPreisDetails()['variant01']);
		if($this->owner->InPreSale){
			return new ArrayData(["StartInventory"=>$this->owner->PreSaleStartInventory,"CurrentInventory"=>$this->owner->FreeQuantity($this->getPreisDetails())['QuantityLeft']]);	 
		}else{
			return false;
		}
	}
	public function Reserved(){
		// Ware gilt nur so lange als reserviert, wie ihr Warenkorb lebt --
		// die Frist steht in Reservierung::dauer().
		return Reservierung::menge(
			Reservierung::fuer((int)$this->owner->ProductID, (int)$this->owner->ID)
		);
	}
	/**
	 * Die Bestellpositionen, die zum *laufenden* Vorverkauf dieser Variante
	 * gehoeren.
	 *
	 * Frueher wurde das ueber das Anlagedatum hergeleitet ("alles ab
	 * PreSaleStart"). Das zaehlt zwangslaeufig auch Positionen aus anderen
	 * Kampagnen mit, sobald deren Datum spaeter liegt -- und bricht ganz
	 * zusammen, sobald ein Reset PreSaleStart nullt. Seit jede Position ihre
	 * PreSaleID mitfuehrt, laesst sich exakt filtern.
	 *
	 * Der Datums-Rueckfall bleibt fuer Bestaende ohne Kampagnen-Datensatz.
	 */
	public function PreSale_Containers(){
		$base=OrderProfileFeature_ProductContainer::get()->filter([
			'ProductID'=>$this->owner->ProductID,
			'PriceBlockElementID'=>$this->owner->ID
		]);
		$product=$this->owner->Product();
		$preSale=($product && $product->exists()) ? PreSale::activeFor($product->ParentID) : null;
		if($preSale){
			return $base->filter('PreSaleID',$preSale->ID);
		}
		if(!$this->owner->PreSaleStart){
			// Ohne Kampagne und ohne Startdatum gibt es nichts zu zaehlen --
			// sonst wuerde hier die gesamte Historie zusammengerechnet.
			return $base->filter('ID',0);
		}
		return $base->filter('Created:GreaterThanOrEqual',$this->owner->PreSaleStart);
	}

	public function PreSale_SoldAndReserved(){
		$returnValue= new ArrayList();
		$productContainers=$this->PreSale_Containers();
		// Hole alle schon verkauften Produkte
		$returnValue->Sold=0;
		foreach($productContainers->filter(["ClientOrderID:GreaterThan"=>0]) as $pC){
				//Injector::inst()->get(LoggerInterface::class)->error('verkauftes Produkt > PreSaleStart= pBe->ID'.$pC->PriceBlockElementID." PreSaleStrt=".$this->owner->PreSaleStart);
				$returnValue->Sold+=$pC->Quantity;			
		}
		// Hole alle reservierten Produkte
		$returnValue->Reserved=0;		
		// Nur lebende Warenkoerbe zaehlen -- ein abgebrochener Einkauf darf die
		// Ware nicht dauerhaft sperren.
		foreach(Reservierung::nurGueltige($productContainers) as $pC){
				//Injector::inst()->get(LoggerInterface::class)->error('verkauftes Produkt > PreSaleStart= pBe->ID'.$pC->PriceBlockElementID." PreSaleStrt=".$this->owner->PreSaleStart);
				$returnValue->Reserved+=$pC->Quantity;			
		}
		$returnValue->Total=$returnValue->Sold+$returnValue->Reserved;
		return $returnValue;		
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
		if($this->owner->PreSaleStartInventory==0 && $this->owner->InPreSale){
			$this->owner->PreSaleStartInventory=$this->owner->PreSaleInventory;
		}
	}
	public function onAfterWrite(){
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
			// Quantity, nicht Inventory: eine Bestellposition hat keinen
			// Warenbestand, sondern eine bestellte Menge.
			$totalQuantity=Reservierung::menge($this->ReservedProductContainers());
			$fq=(($this->getOwner()->Inventory)-($totalQuantity));
			if($fq<0){
				$fq=0;
			}
		}
		return $fq;
	}
	public function ReservedProductContainers(){
		// Die gueltigen Reservierungen dieser Variante. Frueher stand hier eine
		// Schleife ueber alle Warenkoerbe, die auf $pd zugriff -- eine Variable,
		// die es in dieser Methode nie gab. Sie filterte damit auf ProductID
		// NULL und lieferte immer eine leere Liste.
		return Reservierung::fuer((int)$this->owner->ProductID, (int)$this->owner->ID);
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
