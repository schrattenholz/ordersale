<?php 	

namespace Schrattenholz\OrderSale;


use Silverstripe\ORM\DataExtension;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;

use SilverStripe\Control\Email\Email;
use SilverStripe\Control\RequestHandler;

use Schrattenholz\Order\Product;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\OrderConfig;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientContainer;
use Schrattenholz\OrderProfileFeature\ProductOption;
use Schrattenholz\OrderProfileFeature\ProductOptions_ProductContainer;
use Schrattenholz\OrderProfileFeature\ProductOptions_Product;
use Schrattenholz\OrderProfileFeature\ProductOptions_Preis;

use SilverStripe\ORM\ValidationException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
class OrderSale_OrderExtension extends DataExtension {
	private static $allowed_actions = array (
		'ClearBasket',
		'addToList',
		'getBasket',
		'calcPreis',
		'getSingleProduct',
		'getLinkCheckoutAddress',
		'setCheckoutAddress',
		'getCheckoutAddress',
		'makeOrder',
		'getLocations',
		'checkIfProductInBasket',
		'getWarenkorbData',
		'loadSelectedParameters',
		'logoutInactiveUser',
		'formattedNumber',
		'OrderConfig',
		'GetActualQuantity',
		'FreeQuantity',
		'FreeQuantityAjax',
		'FreeProductQuantity',
		'VacReadable',
		'saveClientOrderTitle',
		'FreeQuantity_ProductList'
	);
	

	public function VacReadable($data){
		if($data==true){
			return "ja";
		}else{
			return "nein";
		}
	}

	public function setWarenkorbData($data){
		$this->getOwner()->getSession()->set('warenkorb', implode('|||',$data));
		$this->getOwner()->getSession()->set('warenkorb_start_time', time());
	}
	public function BooleanVac($vac){
		
		if($vac=="on" || $vac==1){
			return 1;
		}else{
			return 0;
		}
	}
	public function checkIfProductInBasket($pd){
		
		if(!is_array($pd)){
			$pd=$this->owner->genProductdata($pd);
		}
		$basket=$this->getOwner()->getBasket();
		if($basket){
			$productContainer=$this->getProductContainer($pd);
			
			if($productContainer){
				
				return $productContainer->Quantity;
			}
			//Kein Produkt gefunden
			return false;//$this->getOwner()->httpError(500, 'Kein Produkt gefunden');
		}else{
			//Kein Basket vorhanden
			$this->getOwner()->CreateBasket();
			return false;//$this->getOwner()->httpError(500, 'Basket nicht vorhanden');
		}
	}
	public function OpenPreSaleProductInBasket(){
		$basket=$this->getOwner()->getBasket();
		$preSaleProducts=new ArrayList();
		foreach($basket->ProductContainers() as $pc){			
			if($pc->PriceBlockElement()->getPreSaleMode()=="openpresale"){
				$preSaleProducts->push($pc->PriceBlockElement());
				$deliverySetup= $pc->PriceBlockElement()->DeliverySetup();
				Injector::inst()->get(LoggerInterface::class)->error('_____ openpresale'.$deliverySetup);
			}		
		}
		if(isset($deliverySetup)){
			return new ArrayData(array("DeliverySetup"=>$deliverySetup,"PreSaleProducts"=>$preSaleProducts->removeDuplicates('ID')));			
		}else{
			return false;		
		}
	}
	public function getProductContainer($pd){
		$basket=$this->getOwner()->getBasket();
		
		if(isset($pd['variant01']) && $pd['variant01']>0){
			$hit=false;
			foreach($basket->ProductContainers()->filter(['ProductID'=>$pd['productID'],'PriceBlockElementID'=>$pd['variant01']]) as $pC){
				
				$hit=true;
				$txt="";
				foreach($pC->ProductOptions() as $pO){
					$pC_pO=ProductOptions_ProductContainer::get()->filter(['ProductOptionID'=>$pO->ID,'OrderProfileFeature_ProductContainerID'=>$pC->ID])->First();
					$txt.=$pO->ID." = ".$pC_pO->Active.", ";
					foreach($pd['productoptions'] as $setPO){
						
						if($pC_pO->Active!=$setPO['value'] && $pC_pO->ProductOptionID==$setPO['id']){
							$hit=false;
						}
					}
				}
				
				if ($hit){
					
					return $pC;
				}
			}
			
			return false;
		}else{
			$hit=false;
			foreach($basket->ProductContainers()->filter(['ProductID'=>$pd['productID']]) as $pC){
				
				$hit=true;
				$txt="";
				foreach($pC->ProductOptions() as $pO){
					$pC_pO=ProductOptions_ProductContainer::get()->filter(['ProductOptionID'=>$pO->ID,'OrderProfileFeature_ProductContainerID'=>$pC->ID])->First();
					$txt.=$pO->ID." = ".$pC_pO->Active.", ";
					foreach($pd['productoptions'] as $setPO){
						
						if($pC_pO->Active!=$setPO['value'] && $pC_pO->ProductOptionID==$setPO['id']){
							$hit=false;
						}
					}
				}
				
				if ($hit){
					
					return $pC;
				}
			}
			
			return false;
		}
		
	}
	public function getOrderCustomerGroupID(){
		return OrderCustomerGroup::get()->filter('GroupID',$this->getOwner()->CurrentGroup()->ID)->First()->ID;
	}
	public function addProduct($pd){
Injector::inst()->get(LoggerInterface::class)->error('addProduct-----------------');
		//neues Produkt anlegen
			$returnValues=new ArrayList(['Status'=>'error','Message'=>false,'Value'=>false]);
			$basket=$this->getOwner()->getBasket();
			$productContainer=OrderProfileFeature_ProductContainer::create();
			$productContainer->ProductID=$pd['productID'];
			
			$productDetails=$this->owner->getProductDetails($pd);
			

			if(isset($pd['variant01'])){
				$productContainer->PriceBlockElementID=$pd['variant01'];
			}
			
			//Berechnung ob quantity noch voll vorhanden ist
			$possibleQuantity=$this->QuantityCheck($pd);
			
			if($pd['quantity']==$possibleQuantity || $productDetails->InfiniteInventory){
				//Quantity ist noch vorhanden
				$productContainer->Quantity=(int)$pd['quantity'];
				
				$productContainer->ProductSort=Product::get()->byID($pd['productID'])->GlobalProductSort;
				
				$basket->ProductContainers()->add($productContainer);
				
				$this->owner->addProductOptions($pd,$productContainer);
				//return $this->getOwner()->httpError(500,'addProduct: ausverkuft '.$productContainer->Quantity." quan=".$pd['quantity']);
				//return '1|added|'.$this->ProductsInBasket();
				
				$returnValues->Status='good';
				$returnValues->Message="Das Produkt wurde dem Warenkorb hinzugefügt";
				$quantities=$this->FreeQuantity($pd);
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$productPrice="";
				if ($productContainer->PriceBlockElement()->CaPrice ){
					$productPrice.="ca. ";
				}
				$productPrice.=$this->owner->formattedNumber($productContainer->CompletePrice()->Price). " €";
				$totalPrice="";
				if ($this->owner->getBasket()->TotalPrice()->CaPrice ){
					$totalPrice.="ca. ";
				}
				$totalPrice.=$this->owner->formattedNumber($this->owner->getBasket()->TotalPrice()->Price). " €";
				$returnValues->Value=[
					"ProductPrice"=>$productPrice,
					"TotalPrice"=>$totalPrice,
					"QuantityLeft"=>$clientsQuantityMax
				];
				//return json_encode($returnValues);
				//$returnValues->Value=$this->owner->ProductsInBasket();
				
				// ExtensionHook
				Injector::inst()->get(LoggerInterface::class)->error('ExtensionHook-----------------addProduct_basketSetUp->');
				$vars=new ArrayData(array("Basket"=>$basket,"ProductDetails"=>$productDetails));
				$this->owner->extend('addProduct_basketSetUp', $vars);

				
				return json_encode($returnValues);
				
			}else if($possibleQuantity>0){
				// Quantity wird uaf moeglichen Wert herabgesetzt
				$productContainer->Quantity=$possibleQuantity;
				$productContainer->ProductSort=Product::get()->byID($pd['productID'])->GlobalProductSort;
				$basket->ProductContainers()->add($productContainer);
				$this->owner->addProductOptions($pd,$productContainer);
				//return "2|quantityrecalculated|".$this->ProductsInBasket();
				$returnValues->Status='good';
				$returnValues->Message="Die gewünschte Menge ist nicht mehr vorhanden. Wir haben die maximale Anzahl von ".$possibleQuantity." für Sie reserviert.";
				$returnValues->Value=$this->owner->ProductsInBasket();
				
				// ExtensionHook
				Injector::inst()->get(LoggerInterface::class)->error('ExtensionHook-----------------addProduct_basketSetUp->');
				$vars=new ArrayData(array("Basket"=>$basket,"ProductDetails"=>$productDetails));
				$this->owner->extend('addProduct_basketSetUp', $vars);
				
				
				return json_encode($returnValues);
			}else{
				// Produkt ist ausverkuft
				//return "0|stocklimitreached|".$this->ProductsInBasket();
				$returnValues->Status='error';
				$returnValues->Message="Das gewünschte Produkt ist nicht mehr verfügbar";
				$returnValues->Value="";
				return json_encode($returnValues);
			}
		
	}
	public function addProductOptions($pd,$pC){
		// productdata, productcontainer
		$productDetails=$this->owner->getProductDetails($pd);
		
		foreach($productDetails->ProductOptions() as $po){
				$po_pc=ProductOptions_ProductContainer::get()->where(["ProductOptionID"=>$po->ID,"OrderProfileFeature_ProductContainerID"=>$pC->ID])->First();
				if(!$po_pc){
					$po_pc=ProductOption::get()->byID($po->ID);
					$newpo=$pC->ProductOptions()->add($po_pc);
				}
				$po_pc=ProductOptions_ProductContainer::get()->where(["ProductOptionID"=>$po->ID,"OrderProfileFeature_ProductContainerID"=>$pC->ID])->First();
					
				if(isset($pd['variant01'])){
					// Wenn es Staffelpreise gibt, nimm die Werte aus den Staffelpreisen
					$po_pc->Price=ProductOptions_Preis::get()->where(["ProductOptionID"=>$po->ID,"PreisID"=>$pd['variant01']])->First()->Price;
				}else{
					// Es gibt keine Staffelpreise. Es werden die Defaultwerte des Produktes genommen
					$po_pc->Price=ProductOptions_Product::get()->where(["ProductOptionID"=>$po->ID,"ProductID"=>$pd['productID']])->First()->Price;
				}
				foreach($pd['productoptions'] as $act_po){
					if($act_po['id']==$po->ID){
						$po_pc->Active=$act_po['value'];
						$po_pc->write();
					}
				}

				
			}
	}
	public function editProduct($pd){
		$returnValues=new ArrayList(['Status'=>'error','Message'=>false,'Value'=>false]);
		//return $this->getOwner()->httpError(500,'editProduct= '.$blockedQuantity);
		//vorhandenes Produkt aktualisieren
		
		$productDetails=$this->owner->getProductDetails($pd);
		
		$productContainer=$this->getProductContainer($pd);
		
		
		$this->owner->addProductOptions($pd,$productContainer);
		$possibleQuantity=$this->QuantityCheck($pd);
		if($pd['quantity']==$possibleQuantity || $productDetails->InfiniteInventory){
			//return $this->getOwner()->httpError(500,$pd['productID']."editProduct GlobalProductSort=".Product::get()->byID($pd['productID'])->GlobalProductSort);
			//Quantity ist noch vorhanden
			$productContainer->Quantity=$pd['quantity'];
			$productContainer->ProductSort=Product::get()->byID($pd['productID'])->GlobalProductSort;
			if($productContainer->write()){
				//return '1|edited|'.$this->ProductsInBasket();
				$returnValues->Status='good';
				$returnValues->Message="Das Produkt wurde aktualisiert";
				$quantities=$this->FreeQuantity($pd);
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$productPrice="";
				if ($productContainer->PriceBlockElement()->CaPrice ){
					$productPrice.="ca. ";
				}
				$productPrice.=$this->owner->formattedNumber($productContainer->CompletePrice()->Price). " €";
				$totalPrice="";
				$vat=$this->owner->formattedNumber($this->owner->getBasket()->TotalPrice()->Vat). " €";
				if ($this->owner->getBasket()->TotalPrice()->CaPrice ){
					$totalPrice.="ca. ";
				}
				$totalPrice.=$this->owner->formattedNumber($this->owner->getBasket()->TotalPrice()->Price). " €";
				$returnValues->Value=[
					"ProductPrice"=>$productPrice,
					"TotalPrice"=>$totalPrice,
					"TotalVat"=>$vat,
					"QuantityLeft"=>$clientsQuantityMax
				];
				return json_encode($returnValues);
			}else{
				return '0|error';
			}
			
		}else if($possibleQuantity>0){
			// Quantity wird uaf moeglichen Wert herabgesetzt
			$productContainer->Quantity=$possibleQuantity;
			//return $this->getOwner()->httpError(500,$pd['productID']."editProduct GlobalProductSort=".Product::get()->byID($pd['productID'])->GlobalProductSort);
			$productContainer->ProductSort=Product::get()->byID($pd['productID'])->GlobalProductSort;
			//return $this->getOwner()->httpError(500,'editProduct possibleQuantity= '.$possibleQuantity);
			if($productContainer->write()){
					$returnValues->Status='info';
					$returnValues->Message="Die gewünschte Menge ist nicht mehr vorhanden. Wir haben die maximale Anzahl von ".$possibleQuantity." für Sie reserviert.";
					$quantities=$this->FreeQuantity($pd);
					$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
					$returnValues->Value=$clientsQuantityMax;
					return json_encode($returnValues);
			}else{
				return '0|error';
			}
		}else{
			// Produkt ist ausverkuft
			//return "0|stocklimitreached|".$this->ProductsInBasket();
			$returnValues->Status='error';
			$returnValues->Message="Das Produkt ist nicht verfügbar";
			$returnValues->Value="";
			return json_encode($returnValues);
		}
	}
	
	public function ReservedProductContainers($pd){
		//Produkte, die gerade in einem Warenkorb liegen, aber noch nicht bestellt worden sind.
		//Sie können von anderen Bestellern nicht in den Warenkorb gelegt werden, da es sonst zur Überbuchung käme
		$now = date("Y-m-d H:i:s");
		$timestamp = "2016-04-20 00:37:15";
		$start_date = date($now);
		$expires = strtotime('-11 minute', strtotime($now));
		$date_diff=($expires-strtotime($now)) / 86400;
		$productDetails=$this->owner->getProductDetails($pd);

		if(!$productDetails->InfiniteInventory){
			//Wenn das Produkt einen Warenbestand benutzt, muss die Anzahl der Reservierungen ermittelt werden 
			
			$reservedQuantity=0;
			$tmpPc=new ArrayList();
			foreach(OrderProfileFeature_Basket::get() as $basket){
						
				if($productDetails->ClassName=="Schrattenholz\\Order\\Preis"){
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
	public function CalcReservedQuantity($pd){
		$blockedQuantity=0;
		foreach($this->ReservedProductContainers($pd) as $productContainer){
			$blockedQuantity+=$productContainer->Quantity;
		}

		return $blockedQuantity;
	}
	public function CalcNewBlockedQuantity($pd){
		$reservedQuantity=$this->CalcReservedQuantity($pd);
		$clientsProductContainer=$this->getProductContainer($pd);
		
		if($clientsProductContainer){
			$reservedQuantity-=$clientsProductContainer->Quantity;
		}
		$blockedQuantity=$reservedQuantity+$pd['quantity'];
		return $blockedQuantity;
		
		$blockedQuantity=0;
		
		$productContainers=$this->ReservedProductContainers($pd);
		//return $this->getOwner()->httpError(500,'blockedQuantity= '.$blockedQuantity);
		if($productContainers->Count()>0){
			//
			$clientHasProduct=false;
			foreach($productContainers as $productContainer){
				
				if($this->getOwner()->getBasket()->ID!=$productContainer->BasketID){ 
				//return $this->getOwner()->httpError(500,' anderer Kunde '.$productContainers->Count());
					$blockedQuantity=$blockedQuantity+$productContainer->Quantity;
					//return $this->getOwner()->httpError(500,'blockedQuantity= '.$blockedQuantity);
				}else if($this->getOwner()->getBasket()->ID==$productContainer->BasketID && $clientsProductContainer){

					if($productContainer->ID==$clientsProductContainer->ID){
						// Das Produkt wird aktualisiert. Die Quantity aus der Datenbank 
						// wird ignoriert und der neue Wert $pd['quantity'] stattdessen 
						// genommen um den zukuenftigen blockedQuantity-Wert zu bekommen
						//return $this->getOwner()->httpError(500,'blockedQuantityaaaaa= '.$pd['quantity']);
						//return $this->getOwner()->httpError(500,' selber Kunde '.$productContainers->Count());
						$blockedQuantity=$blockedQuantity+$pd['quantity'];
						$clientHasProduct=true;
					}else{
						// Das Produkt wird neu angelegt. 
						//return $this->getOwner()->httpError(500,'blockedQuantityaaaaa= '.$pd['quantity']);
						//return $this->getOwner()->httpError(500,' selber Kunde '.$productContainers->Count());
						$blockedQuantity=$blockedQuantity+$productContainer->Quantity;
					}

					
				}else{
					
					$blockedQuantity=$blockedQuantity+$productContainer->Quantity;
					
					
				}
			}
			if(!$clientHasProduct){
				$blockedQuantity=$blockedQuantity+$pd['quantity'];
			}
			return $blockedQuantity;
		}else{
			//return $this->getOwner()->httpError(500,'CalcNewBlockedQuantity no productContainer found '.$pd['quantity']);
			return $pd['quantity'];
		}
	}
	
	public function saveClientOrderTitle($data){
		$returnValues=new ArrayList(['Status'=>'error','Message'=>false,'Value'=>false]);
		$cO=OrderProfileFeature_ClientOrder::get()->byID($data['id']);
		$cO->Title=$data['title'];
		if($cO->write()){
			$returnValues->Status='good';
			$returnValues->Message="Der Titel wurde gespeichert.";
			$returnValues->Value=$data['id'];
		}else{
			$returnValues->Status='error';
			$returnValues->Message="Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.";
			$returnValues->Value=$id;
		}
		return json_encode($returnValues);
	}
	public function QuantityCheck($pd){
		if(isset($pd['variant01'])){
			$product=Preis::get()->byID($pd['variant01']);
		}else{
			$product=Product::get()->byID(intval($pd['productID']));
		}
		$totalQuantity=$product->Inventory;
		if(!$product->InfiniteInventory){
			$newBlockedQuantity=$this->CalcNewBlockedQuantity($pd);
			if($totalQuantity>=$newBlockedQuantity){
				//return $this->getOwner()->httpError(500," nicht reduzieren");
				return $pd['quantity'];
			}else if($totalQuantity-($newBlockedQuantity-$pd['quantity'])>0){
				
			
				$reducedQuantity=$totalQuantity-($newBlockedQuantity-$pd['quantity']);
				
				// Die Bestellmenge wird auf die hoechst moegliche Bestellmenge reduziert
				
				//$priceBlockElement=Preis::get()->byID($pd['variant01']);
				
				return $reducedQuantity;
			}else{
				return false;
			}
		}else{
			// Das Produkt hat einen unendlicen Warenbestand
			return $pd['quantity'];
		}
	}
	// Produkt in den Warenkorb
	public function addToList($data){
		$action=$data['action'];
		$error=false;
		
		$pd=$this->owner->genProductdata(json_decode(utf8_encode($data['orderedProduct']),true));

		$returnValues=new ArrayList(['Status'=>'error','Message'=>false,'Value'=>false]);
		//Daten validieren
		if($pd['quantity']!=0){
			if($this->checkIfProductInBasket($pd)){
				if($action=="list"){
					$clientsProductContainer=$this->getProductContainer($pd);
					$pd['quantity']=$pd['quantity']+$clientsProductContainer->Quantity;
				}
				//vorhandenes Produkt aktualisieren
				return $this->editProduct($pd);
			}else if(!$this->checkIfProductInBasket($pd)){
				//neues Produkt anlegen
				return $this->addProduct($pd);
				
			}/*else{
				return "0|stocklimitreached";
			}*/
		}else if($this->checkIfProductInBasket($pd)){
			return $this->owner->removeProductFromBasket($data);
		}else{
			// Es fehlen Eingaben
			$returnValues->Status='error';
			$returnValues->Message="Es fehlen Eingaben.";
			$returnValues->Value=$this->owner->ProductsInBasket();
			return json_encode($returnValues);
		}
	}
	
	public function loadSelectedParametersFromTemplate($id,$v){
		//$id = ProdcutID
		//$v = PriceBlockElementID
		$product=$this->owner;
			// //spezifisches Produkt benutzen
			
			//$quantity=$this->owner->checkIfProductInBasket(array('id'=>$_GET['id'],'variant01'=>$_GET['v'],'vac'=>$_GET['vac'],'productID'=>$this->owner->ID));
			if($v>0){
				$variantID=$v;
				
				$quantities=$this->owner->FreeQuantity(array('id'=>$id,'variant01'=>$v,'productID'=>$id));
				$quantity=$quantities['ClientsQuantity'];
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$pd=['variant01'=>$variantID,'productID'=>$id];
				return new ArrayData(['ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantities['ClientsQuantity'],'QuantityLeft'=>$quantities['QuantityLeft'],'Variant01'=>$variantID]);
			}else{
				$quantities=$this->owner->FreeQuantity(array('id'=>$variantID,'productID'=>$id));

				$quantity=$quantities['ClientsQuantity'];
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$pd=['productID'=>$id];
				return new ArrayData(['ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantities['ClientsQuantity'],'QuantityLeft'=>$quantities['QuantityLeft'],'Variant01'=>$variantID]);
			}
		
	}
		public function loadSelectedParameters($priceBlockElementID=0){
		$product=$this->owner;
		if(isset($_GET['v'])){
			$priceBlockElementID=$_GET['v'];
		}
		if(isset($_GET['id']) || $priceBlockElementID){
			// //spezifisches Produkt benutzen
			Injector::inst()->get(LoggerInterface::class)->error('-----------------____-----_____ spezifisches Produkt benutzen');
			//$quantity=$this->owner->checkIfProductInBasket(array('id'=>$product->ID,'variant01'=>$variantID,'vac'=>$_GET['vac'],'productID'=>$this->owner->ID));
			if($priceBlockElementID){
				$variantID=$priceBlockElementID;
				$quantities=$this->owner->FreeQuantity(array('id'=>$product->ID,'variant01'=>$variantID,'productID'=>$product->ID));
				
				$quantity=$quantities['ClientsQuantity'];
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$pd=['variant01'=>$variantID,'productID'=>$product->ID];
				return new ArrayData(['ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantities['ClientsQuantity'],'QuantityLeft'=>$quantities['QuantityLeft'],'Variant01'=>$variantID]);
			}else{
				$quantities=$this->owner->FreeQuantity(array('id'=>$product->ID,'productID'=>$product->ID));

				$quantity=$quantities['ClientsQuantity'];
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$pd=['productID'=>$product->ID];
				return new ArrayData(['ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantities['ClientsQuantity'],'QuantityLeft'=>$quantities['QuantityLeft'],'Variant01'=>$variantID]);
			}
		}else{
			
			// default-Produkt benutzen
			if($product->GroupPreise()->Count()>0){
				
				$variantID=$product->GroupPreise()->Sort('SortID','ASC')->First()->ID;
				$quantities=$this->owner->FreeQuantity(array('id'=>$product->ID,'variant01'=>$variantID,'productID'=>$product->ID));
				$quantity=$quantities['ClientsQuantity'];
				$quantity=0;
				if($quantities['ClientQuantities']){
					foreach($quantities['ClientQuantities'] as $cq){
						$defaultFound=true;
						foreach($cq->ProductOptions as $po){
							
							if($po->Active){
								$defaultFound=false;
							}
						}
						if($defaultFound){
							
							$quantity=$cq->Quantity;
							break;
						}else{
							$quantity=0;
						}
					}
				}
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])+$quantities['ClientsQuantity'];
				$pd=['variant01'=>$variantID,'productID'=>$product->ID];
				
				return new ArrayData(['ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantities['ClientsQuantity'],'QuantityLeft'=>$quantities['QuantityLeft'],'Variant01'=>$variantID]);
			}else{
				
				$quantities=$this->owner->FreeQuantity(array('id'=>$product->ID,'productID'=>$product->ID,'productoptions'=>$product->ProductOptions()));
				$quantity=0;
				if($quantities['ClientQuantities']){
					foreach($quantities['ClientQuantities'] as $cq){
						$defaultFound=true;
						foreach($cq->ProductOptions as $po){
							
							if($po->Active){
								$defaultFound=false;
							}
						}
						if($defaultFound){
							
							$quantity=$cq->Quantity;
							break;
						}else{
							$quantity=0;
						}
					}
				}
				$clientsQuantityMax=floatval($quantities['QuantityLeft'])."-".$quantity;
				$pd=['productID'=>$product->ID];
				return new ArrayData(array('ProductDetails'=>$quantities['ProductDetails'],'ClientsQuantityMax'=>$clientsQuantityMax,'Quantity'=>$quantity,'QuantityLeft'=>$quantities['QuantityLeft'],'ClientQuantities'=>$quantities['ClientQuantities']));
			}
		}
		return false;
		
	}
	
	public function FreeQuantityAjax($data){
		$pd=$this->owner->genProductdata(json_decode(utf8_encode($data['orderedProduct']),true));
		$quantities=$this->owner->FreeQuantity($pd);
		$quantities['ProductDetails']=$this->owner->getProductDetails($pd)->getQueriedDatabaseFields();
		/*$quantities['ProductDetails']['Portion']=$this->owner->getProductDetails($pd)->Portion;
		$quantities['ProductDetails']['PortionMin']=$this->owner->getProductDetails($pd)->PortionMin;
		$quantities['ProductDetails']['PortionMax']=$this->owner->getProductDetails($pd)->PortionMax;*/
		$quantitisArray=array();
		if(isset($quantities['ClientQuantities']) && count($quantities['ClientQuantities'])>0){
		foreach($quantities["ClientQuantities"] as $cq){
			$productOptionsArray=array();
			foreach($cq->ProductOptions as $po){
				array_push($productOptionsArray,array("ID"=>$po->ID,"Active"=>$po->Active,"Price"=>$po->Price));
			}
			array_push($quantitisArray,array("ProductContainerID"=>$cq->ProductContainerID,"Quantity"=>$cq->Quantity,"ProductOptions"=>$productOptionsArray));
		}
		$quantities['ClientQuantities']=$quantitisArray;
		}else{
			$quantities['ClientQuantities']=false;
		}
		return json_encode($quantities);
	}
	public function FreeQuantity_ProductList($productList){
		
		$productList=json_decode(utf8_encode($productList['productList']),true);
		$productData=array();
		foreach($productList as $p){
			$data=$this->FreeQuantity(['productID'=>$p['id'],'variant01'=>$p['variant01']]);
			$data['ProductID']=$p['id'];
			$data['VariantID']=$p['variant01'];
			array_push($productData,$data);
			Injector::inst()->get(LoggerInterface::class)->error($this->FreeQuantity(['productID'=>$p['id'],'variant01'=>$p['variant01']])['QuantityLeft']);
			
		}
		
		return json_encode($productData);
	}
	public function FreeQuantity($pd){

		$productDetails=$this->owner->getProductDetails($pd);
		
		$blockedFromOtherUsers=0;
		$productContainers=false;
		if($this->getOwner()->getBasket() && Product::get()->byID($pd['productID'])->InPreSale){
			// Es besteht fuer den Kunden bereits ein Warenkorb und das Produkt wird abverkauft
			
			$formerProductContainers=$this->ReservedProductContainers($pd)->exclude('BasketID',$this->getOwner()->getBasket()->ID);
			if(isset($pd['variant01'])){
			$productContainers=OrderProfileFeature_ProductContainer::get()->filter(['ProductID'=>$pd['productID'],'PriceBlockElementID'=>$pd['variant01'],'BasketID'=>$this->getOwner()->getBasket()->ID]);
			
			}else{
			$productContainers=OrderProfileFeature_ProductContainer::get()->filter(['ProductID'=>$pd['productID'],'BasketID'=>$this->getOwner()->getBasket()->ID]);
			}
		}else if($this->getOwner()->getBasket() && !Product::get()->byID($pd['productID'])->InPreSale){
			// Kein Abverkauf und es besteht ein Warenkorb
			if(isset($pd['variant01'])){
			$productContainers=OrderProfileFeature_ProductContainer::get()->filter(['ProductID'=>$pd['productID'],'PriceBlockElementID'=>$pd['variant01'],'BasketID'=>$this->getOwner()->getBasket()->ID]);
			}else{
			$productContainers=OrderProfileFeature_ProductContainer::get()->filter(['ProductID'=>$pd['productID'],'BasketID'=>$this->getOwner()->getBasket()->ID]);
			}
		}else{
			$formerProductContainers=$this->ReservedProductContainers($pd);
		}
		if($productDetails->InPreSale){
			// Wen es ein Abverkauf ist, muss die Verkaufanzahl ermittelt werden
			if(isset($formerProductContainers)){
				foreach ($formerProductContainers as $pC){
						$blockedFromOtherUsers=$blockedFromOtherUsers+$pC->Quantity;
				}
			}
		}
		if($productContainers){
			$clientsQuantity=$productContainers->First();
			if($clientsQuantity){
				$clientsQuantity=$clientsQuantity->Quantity;
			}else{
				$clientsQuantity=0;
			}
			$clientQuantities=new ArrayList();
			foreach($productContainers as $pC){
				$pos=new ArrayList();
				foreach($pC->ProductOptions() as $po){
					$po_pc=ProductOptions_ProductContainer::get()->filter(["ProductOptionID"=>$po->ID,"OrderProfileFeature_ProductContainerID"=>$pC->ID])->First();
					$pos->push(new ArrayData(["ID"=>$po->ID,"Active"=>$po_pc->Active,"Price"=>$po_pc->Price]));
				}
				$pcArray=$clientQuantities->push(new ArrayData(["ProductContainerID"=>$pC->ID,"Quantity"=>$pC->Quantity,"ProductOptions"=>$pos]));
			}
		}else{
			$clientsQuantity=0;
			$clientQuantities=[];
		}
		if(!$productDetails->InfiniteInventory){
			
			$quantityleft=(($productDetails->Inventory)-($this->CalcReservedQuantity($pd)));
			if($quantityleft<0){
				$quantityleft=0;
			}
			return [
				"ProductDetails"=>$productDetails,
				"QuantityLeft"=>$quantityleft,
				"ClientsQuantity"=>$clientsQuantity,
				"ClientQuantities"=>$clientQuantities
			];
		}else{
			return [
				"ProductDetails"=>$productDetails,
				"QuantityLeft"=>"Auf Lager",
				"ClientsQuantity"=>$clientsQuantity,
				"ClientQuantities"=>$clientQuantities
			];
		}
	}
	public	function makeOrder(){
		$basket= $this->getOwner()->getBasket();
		$order=OrderProfileFeature_ClientOrder::get()->filter('ClientContainerID',$basket->ClientContainerID);

			$order=OrderProfileFeature_ClientOrder::create();
			$order->ClientContainerID=$basket->ClientContainerID;

		$checkoutAddress=$this->getOwner()->getCheckoutAddress();
		$vars=new ArrayData(array("Basket"=>$basket,"Order"=>$order));
		$this->owner->extend('makeOrder_ClientOrder', $vars);
		$order->AdditionalNotes=$basket->AdditionalNotes;
		if($order->write()){
			$this->getOwner()->getSession()->set('orderid', $order->ID);
			foreach($basket->ProductContainers() as $pc){
				$pc->ClientOrderID=$order->ID;
				$order->ProductContainers()->add($pc);
				// BasketID auf Null setzten
				$pc->BasketID=0;
				$pc->write();
				$basket->ClientContainerID=0;
				$basket->write();
				// Warenbestand anpassen
				
				$product=$this->owner->getProductDetailsWrapper($pc->ProductID,$pc->PriceBlockElementID);
				$product->Inventory=intval($product->Inventory)-intval($pc->Quantity);
				$product->write();
			}
		$emailToClient = Email::create()
		->setHTMLTemplate('Schrattenholz\\OrderProfileFeature\\Layout\\ConfirmationClient') 
		->setData([
				'Page'=>$this->owner,
				'BaseHref' => $_SERVER['DOCUMENT_ROOT'],
				'Basket' => $order,
				'CheckoutAddress' => $checkoutAddress,
				'OrderConfig'=>OrderConfig::get()->First()
		])
		->setFrom(OrderConfig::get()->First()->OrderEmail)
		->setTo($checkoutAddress->Email)
		->setSubject("Bestellbestätigung | ".$order->ID);
		$emailToClient->send();
		$emailToSeller = Email::create()
		->setHTMLTemplate('Schrattenholz\\OrderProfileFeature\\Layout\\Confirmation') 
		->setData([
			'Page'=>$this->owner,
			'BaseHref' => $_SERVER['DOCUMENT_ROOT'],
			'Basket' => $order,
			'CheckoutAddress' => $checkoutAddress,
			'OrderConfig'=>OrderConfig::get()->First()
		])
		->setFrom(OrderConfig::get()->First()->OrderEmail)
		->setTo(OrderConfig::get()->First()->OrderEmail)
		->setSubject("Neue Bestellung |".$order->ID." | ".$checkoutAddress->Company." ".$checkoutAddress->Surname);
		
		$vars=new ArrayData(array("Email"=>$emailToSeller,"CheckoutAddress"=>$checkoutAddress,"Order"=>$order));
		$this->owner->extend('makeOrder_EmailToSeller', $vars);
		}
		//$order->ProductContainers()->write();
		$this->AfterMakeOrder($order);
		
		if($emailToSeller->send()){
			//$this->getOwner()->ClearBasket();
			
		}
	}
	
	/*$productContainer=OrderProfileFeature_ProductContainer::get()->filter(
		[
			'ProductID'=>$pd['productID'],
			'PriceBlockElementID'=>$pd['variant01'],
			'Created:GreaterThanOrEqual'=>strtotime($product->PreSaleStart)
		]);
	*/
	public function AfterMakeOrder($order){
//Injector::inst()->get(LoggerInterface::class)->error(' OrderSale_OrderExtension/AfterMakeOrder presaleprodukte='.$order->ProductContainers()->leftJoin('Preis','Preis.ID=OrderProfileFeature_ProductContainer.PriceBlockElementID','Preis')->filter('PriceBlockElement.InPreSale',1)->First()->PriceBlockElement()->Content);
		foreach($order->ProductContainers()->leftJoin('Preis','Preis.ID=OrderProfileFeature_ProductContainer.PriceBlockElementID')->where('Preis.InPreSale',1) as $pcOrder){
			//return $this->getOwner()->httpError(500, 'abverkauf check');
			Injector::inst()->get(LoggerInterface::class)->error(' OrderSale_OrderExtension/AfterMakeOrder Warenkorb hat Abverkaufprodukt');
			if($pcOrder->PriceBlockElement()->checkSoldQuantity()=="salefinished"){
				Injector::inst()->get(LoggerInterface::class)->error(' OrderSale_OrderExtension/AfterMakeOrderProdukt ist abverkauft');
				$priceBlockElements=Product::get()->byID($pcOrder->ProductID)->Preise();
				$sold=true;
				foreach($priceBlockElements as $pBE){
					if($pBE->Quantity!=$pBE->SoldQuantity){
						$sold = false;
					}
				}
				//throw new ValidationException('Abverkaufte Menge neu berechnen sold='.$sold);
				if($sold){
					Injector::inst()->get(LoggerInterface::class)->error(' OrderSale_OrderExtension/AfterMakeOrder Alle Vorberkauf Produkte verkauft');
					$email = Email::create()
						->setHTMLTemplate('Schrattenholz\\OrderSale\\Layout\\SaleFinished') 
						->setData([
							'BaseHref' => $_SERVER['DOCUMENT_ROOT'],
							'Product' => $pcOrder->Product(),
							'OrderConfig'=>OrderConfig::get()->First()
						])
						->setFrom(OrderConfig::get()->First()->OrderEmail)
						->setTo(OrderConfig::get()->First()->OrderEmail)
						->setSubject("Abverkauf beendet");
					$email->send();
				}
			}
		}
		$this->getOwner()->ClearBasket();
	}
}
