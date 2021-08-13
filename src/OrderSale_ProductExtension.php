<?php

namespace Schrattenholz\OrderSale;

use Silverstripe\ORM\DataExtension;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldConfig_RelationEditor;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridField_ActionMenu;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use SwiftDevLabs\DuplicateDataObject\Forms\GridField\GridFieldDuplicateAction;
use Silverstripe\ORM\ArrayList;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Security;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use Silverstripe\Security\Group;
use SilverStripe\ORM\ValidationException;

use SilverStripe\Forms\ListboxField;

use Schrattenholz\Order\Attribute;
class OrderSale_ProductExtension extends DataExtension{

	private static $allowed_actions = array (
		'GetActualQuantity',
		'BlockQuantitiy'
	);
	private static $db=[
		'InPreSale' => 'Boolean',
		'PreSaleStart'=>'Date',
		'PreSaleEnd'=>'Date',
		'PreSaleInventory'=>'Int',
		'ResetPreSale'=>'Boolean',
		'InSale'=>'Boolean',
		'SaleDiscount'=>'Decimal(6,2)',
		'SaleFinish'=>'Date'
	];
	

	
	// Extension for Product::getCMSFields
	public function addExtension(FieldList $fields){
		
		//Vorverkauf
		
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('InPreSale','Vorverkauf'));
		$preSaleStartInventory=new NumericField("PreSaleInventory","Zielmenge");
		$preSaleStartInventory->setLocale("DE_De");
		$preSaleStartInventory->setScale(2);
		$fields->addFieldToTab('Root.Verkaufsaktionen',$preSaleStartInventory);
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('ResetPreSale','Vorverkauf zurücksetzen'));
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('PreSaleStart','Start des Vorverkauf'));
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('PreSaleEnd','Ende des Vorverkauf'));
		//$fields->addFieldToTab('Root.Verkaufsaktionen',new LiteralField('Spacer','</hr>'));
		/*
		//Rabatt-Aktion
		$fields->addFieldToTab('Root.Verkaufsaktionen',new CheckboxField('InSale','Rabatt-Aktion'));
		$saleDiscount=new NumericField("SaleDiscount","Preisrabatt in Prozent");
		$saleDiscount->setLocale("DE_De");
		$saleDiscount->setScale(2);
		$fields->addFieldToTab('Root.Verkaufsaktionen',$saleDiscount);
		$fields->addFieldToTab('Root.Verkaufsaktionen',new DateField('SaleFinish','Ende der Rabatt-Aktion'));
		*/
		
		
				//Kilopreise pro Kundengruppe
		$gridFieldConfig=GridFieldConfig::create()
			->addComponent(new GridFieldButtonRow('before'))
			->addComponent($dataColumns=new GridFieldDataColumns())
			->addComponent($editableColumns=new GridFieldEditableColumns())
			->addComponent(new GridFieldSortableHeader())
			->addComponent(new GridFieldFilterHeader())
			->addComponent(new GridFieldPaginator())
			->addComponent(new GridFieldOrderableRows('SortOrder'))
			->addComponent(new GridFieldDuplicateAction())
			->addComponent(new GridFieldEditButton())
			->addComponent(new GridFieldDeleteAction())
			->addComponent(new GridFieldDetailForm())
			->addComponent(new GridField_ActionMenu())
							
				->addComponent(new GridFieldAddNewButton())
			
		;
		/*$dataColumns->setDisplayFields([
			'OrderCustomerGroup.Title' => 'Kundengruppe'
		]);
		$priceField=new NumericField("Price","Preis Grundeinheit");
		$priceField->setLocale("DE_De");
		$priceField->setScale(2);
		*/
		$attributesMap=Attribute::get()->map("ID", "Title", "Bitte auswählen");
		$editableColumns->setDisplayFields(array(
			'Content'  =>array(
					'title'=>'Freitext',
					'callback'=>function($record, $column, $grid) {
						return TextField::create($column);
				}),
			'Attributes'  =>array(
					'title'=>'Produktattribute',
					'callback'=>function($record, $column, $grid) use($attributesMap){
						return  ListboxField::create($column,'Attribute',$attributesMap);
				}),
			'Amount'  =>array(
					'title'=>'Menge',
					'callback'=>function($record, $column, $grid) {
						return NumericField::create($column)->setScale(2);
				}),
			'Inventory'  =>array(
					'title'=>'Stückzahl',
					'callback'=>function($record, $column, $grid) {
						return NumericField::create($column)->setScale(2);
				})
		));
		$fields->addFieldToTab('Root.Staffelpreise', GridField::create(
			'Preise',
			'Staffelelemente',
			$this->getOwner()->Preise()->sort('SortOrder'),
			$gridFieldConfig
		),'Content');
		
		
		
		
		//Staffelpreise, erweitert um Quantity
		/*
		$fields->addFieldToTab('Root.Staffelpreise', $gridfield=GridField::create(
			'Preise',
			'Staffelelemente',
			$this->getOwner()->Preise()->sort('SortOrder'),
			GridFieldConfig_RecordEditor::create()
		),"Content");
		*/

		$dataColumns = $gridFieldConfig->getComponentByType(GridFieldDataColumns::class);
		
		//Wenn PreSales werden die Verkaufszahlen angezeigt
		if($this->getOwner()->InPreSale){
			$dataColumns->setDisplayFields([
				//'Content' => 'Freitext',
				//'DisplayAmount' => 'Menge',
				'CMSPrice'=>'Preise',
				//'Inventory'=>'Stückzahl',
				'SoldQuantity'=>'Verkauft'
			]);
		}else{
			$dataColumns->setDisplayFields([
				//'Content' => 'Freitext',
				//'DisplayAmount' => 'Menge',
				'CMSPrice'=>'Preise',
				//'Inventory'=>'Stückzahl'
			]);
		}
	}
	public function onBeforeWrite(){
		//Wenn ResetSale gesetzt ist, werden die Verkaufszahlen in den Staffelpreisen auf 0 gesetzt
		if($this->getOwner()->ResetSale){
			$this->getOwner()->resetSale();
			$this->getOwner()->ResetSale=0;
		}
		parent::onBeforeWrite();
	}
	public function resetSale(){
		// Reset der Verkaufzahlen in den Staffelpreise auf 0
		foreach($this->getOwner()->Preise() as $pBE){
			$pBE->SoldQuantity=0;
			$pBE->write();
		}
	}
	public function GetActualQuantity($data){
		$staffelpreis=$this->getOwner()->Preise()->byID($data['variant01']);
		return ($staffelpreis->Quantity)-($staffelpreis->BlockedQuantity);
	}
	private function BlockQuantitiy($q,$variant01){
		//Reservierte Stueckzahl in der Datenbank blockieren
		$staffelpreis=Preis::get()->byID($variant01);
		if($staffelpreis->BlockedQuantity+$q<=$staffelpreis->Quantity){
			$blocked=$staffelpreis->BlockedQuantity+=$q;
			$staffelpreis->write();
			return true;
		}else{
			return false;
		}
	}
	private static function CheckIfSaleIsFinished(){
		$product=Product::get()->byID($this->getOwner()->ProductID);
		$productContainer=OrderProfileFeature_ProductContainer::get()->filter(
		[
			'ProductID'=>$this->getOwner()->ID,
			'PriceBlockElementID'=>$this->getOwner()->ID,
			'Created:GreaterThanOrEqual'=>strtotime($product->SaleStart)
		]);
		return $productContainer;
		
	}

}