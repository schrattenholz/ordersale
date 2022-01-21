<section id="product" class="home clearfix pt-md-4 pb-md-6">
<h1>$ProductTest ordersale BasketID=$Basket.ID</h1>
   <div class="container">
		<div class="row">
			<div class="col-md-4 col-sm-6 pt-3">
				<div class="row no-gutters">
					<% if $ProductImages %>
					<div class="col-12 pt-0">
						<div class="imgWrapper"> <img src="$ProductImages.First.Fill(400,300).URL" alt="$ProductImages.First.Filename"/></div>
					</div>
					<% end_if %>
					<div class="col-12 pt-0">
						<h2 class="pb-2">Produktinformation</h2>
						$Content
					</div>
				</div>
			</div>
			<div class="offset-md-1 col-md-7 col-sm-6 pt-3">
				<!--<nav id="projectsNav">
				  <ul class="pager">
					<li><a href=""><i class="icon-left-open-mini"></i>&nbsp;Previous</a></li>
					<li><a href="">Next&nbsp;<i class="icon-right-open-mini"></i></a></li>
				  </ul>
				</nav>-->
				<!--<h2 class="standardTitle"><span>$Title</span><span style="float:right;"><a href="$LinkProductRoot">Zur &Uuml;bersicht</a></h2>-->
				
				<h1>$Title <% if $KiloPrice >0 %><span id="kilo" data-price="$Price">$formattedNumber($KiloPrice) &euro; / kg *</span><% end_if %></h1>
				<h6>
				<% if $Addons %>
				<% loop $Addons %>$Title<% if not $Last %>, <% end_if %><% end_loop %> | <% loop $Ingredients %>$Title<% if not $Last %>, <% end_if %><% end_loop%>
				
				</h6>
				<% end_if %>
				<form id="product-config">
					<div class="row no-gutters">
						<div class="col-md-12">
							<% if $Preise && $ShowPricingTable %>
							Preistabelle
							<table class="table table-striped">								
								<% loop $GroupPreise %><% if $Price !=0 %><tr><td>ca. $DisplayAmount</td><td>ca. $Top.formattedNumber($Price)&euro;/Stk. </td></tr><% end_if %><% end_loop %>
							</table>
							<% else %>
								<% if $Amount>0 %><p>Menge pro Verpackungseinheit: ca. $formattedNumber($Amount)$Unit.Shortcode</p><% end_if %>
							<% end_if %>
						</div>
					</div>
					<div class="row no-gutters">
					<% if $GroupPreise %>
						<div class="col-md-6 pr-md-2">
							
							<h5>Menge pro Einheit</h5>
							<div class="selectric-wrapper">
								<select id="variant01"  style="width:100%;" onchange="refreshSelectedProduct('variantChange')">
								<% loop $GroupPreise %>
									<option value="$ID" data-price="$Price" data-quantity="$FreeQuantity" data-vac="<% if $Top.Vacuum %>1<% else %>$Vacuum<% end_if %>" <% if $Top.loadSelectedParameters.Variant01==$ID %> selected="selected"<% else_if $First %> selected="selected"<% end_if %>><% if $ShowContent %>$Content<% else %>ca. $DisplayAmount<% end_if %></option>
								<% end_loop %>
								</select>
							</div>
							
							
						</div>
						<% end_if %>
						<div class="<% if $Preise %>col-md-6<% else %>col-md-12<% end_if %>">
							<h5>Anzahl</h5>
							<div class="quantity position-relative clearfix d-inline-block align-top">
								<input id="amount" type="number" min="0" max="$GroupPreise.First.FreeQuantity" step="1" onchange="calculatePrice(this.value)" value="<% if $loadSelectedParameters.Quantity %>$loadSelectedParameters.Quantity<% else %>0<% end_if %>">
							</div>
						</div>
					<div class="col-md-12" id="sale_container" style="<% if $InSale %>display:block;<% else %>display:none;<% end_if %>">
						<h3>Noch
						<% if $Top.loadSelectedParameters.Variant01 %>
							<% loop $GroupPreise %>
								<% if $Top.loadSelectedParameters.Variant01==$ID %> <span id="Quantity">$GroupPreise.FreeQuantity</span><% end_if %>
							<% end_loop %>
						<% else %>
						 <span id="Quantity">$GroupPreise.First.FreeQuantity</span>
						<% end_if %>
						 Stk. vorhanden.
						</h3>
					</div>
					<div class="col-md-12" id="vac_container" style="<% if $Vacuum %>display:block;<% else %>display:none;<% end_if %>">

						<input type="checkbox" class="styledcheckbox" id="vac" data-price="$OrderConfig.VacuumPrice" $<% if $Top.loadSelectedParameters.Vac=="on" %>checked="checked"<% end_if %> onchange="refreshSelectedProduct('attributeChange')" /><label for="vac">Die einzelnen Stücke vakuumieren (Aufpreis pro Stück {$OrderConfig.VacuumPrice}€*)<label> 
					</div>
					<div class="col-12">
							<h3 id="price"></h3>
						</div>
					</div>
						<div class="row no-gutters ">
							<div class="col-md-auto">
								<div id="editFunction" <% if $loadSelectedParameters.Quantity==0 || $loadSelectedParameters.Quantity=="" %>style="display:none;"<% end_if %>>
									<a class="btn" href="javascript:addToList('$ID','edit');" title="Die alte Auswahl im Warenkorb wird mit der neuen Auswahl &uuml;berschrieben" ><i class="fas fa-sync-alt"></i>
										<span class="i-name">Produkt aktualisieren</span>
									</a>
									<a class="btn" href="javascript:javascript:removeProductFromBasket('$ID','{$Top.BaseHref}{$Top.Link}');" title="Produkt aus dem Warenkorb entfernen"><i class="fas fa-trash-alt"></i>
									Produkt entfernen</a>
								</div>
								<div id="addFunction" <% if $loadSelectedParameters.Quantity>0 %>style="display:none;"<% end_if %>>
									<a class="btn" href="javascript:addToList('$ID','new');"><i class="fas fa-cart-plus"></i></i>
										<span class="i-name">In den Warenkorb</span>
									</a>
								</div>
							</div>
							<!--
							<div class="col-6">
								<a class="" href="$LinkBasket">Warenkorb &ouml;ffnen</a>
							</div>
							-->
							<div class="col-12 productFooter">
								$OrderConfig.ProductFooter
							</div>
						</div>

					</div>
					
				</form>
				<!--<a href="javascript:resetBasket();">Warenkorb leeren</a>-->
				
        </div>
    </div>
</section>
<script>

function refreshSelectedProduct(action){
		if(jQuery('#variant01 option[value=' + jQuery('#variant01').val() + ']').attr('data-vac')=="1"){
			jQuery('#vac_container').fadeTo(200,1);
		}else{
			jQuery('#vac_container').fadeTo(200,0);
			$( "#vac" ).prop( "checked", false );
		}
		if($('#vac').prop('checked')) { 
			var vac="1";
		}  else {
			var vac="0";
		} 
		//jQuery('#amount').attr('max',jQuery('#variant01 option[value=' + jQuery('#variant01').val() + ']').attr('data-quantity'));
		jQuery.ajax({
			url:"{$Link}/FreeQuantityAjax?orderedProduct="+JSON.stringify(getOrderedProduct()),
			success: function(data){
			data=JSON.parse(data);
				/*
					data.ClientsPossibleQuantity = amount
					data.QuantityLeft
					data.ClientsQuantity
					data.ClientsQuantityVac
				*/
				//alert("action="+action)
				if(vac=="1"){
					var quantity=data.ClientsQuantityVac;
					var possibleQuantity=data.ClientsPossibleQuantityVac;
				}else{
					var quantity=data.ClientsQuantity;
					var possibleQuantity=data.ClientsPossibleQuantity;
				}
				if(quantity>0){
					jQuery('#amount').val(quantity);
					
				}else if(action=="attributeChange"){
				console.log("produkt ist nicht im korb");
				<% if $InSale %>
					if(jQuery('#amount').val()>possibleQuantity){
						console.log("anzahl soll uebernommen werden");
						jQuery('#amount').val(possibleQuantity);
					}else if(jQuery('#amount').val()>0){
					
					}
				<% else %>
				
				
				<% end_if %>
				}else if(action=="variantChange"){
				jQuery('#amount').val(0);
				
				}
				jQuery('#Quantity').html(data.QuantityLeft);
				jQuery('#amount').attr('max',possibleQuantity);
				calculatePrice(quantity);
				showHideEditFunction(quantity);
			}
		});
		
		

		
}
function showHideEditFunction(quantity){
	if(quantity>0){
		$('#editFunction').css('display','block');
		$('#addFunction').css('display','none');
	}else{
		$('#editFunction').css('display','none');
		$('#addFunction').css('display','block');
	}
}
function calculatePrice(quantity){
	if(jQuery('#variant01').length>0){
		var price=jQuery('#variant01  :selected').attr('data-price')*quantity;
		if(getVac()=="on"){
			console.log("vac"+(parseInt(quantity)*parseFloat(jQuery('#vac').attr('data-price'))));
			price=price+(parseInt(quantity)*parseFloat(jQuery('#vac').attr('data-price')));
		}
	}else{
		var price=jQuery('#variant01  :selected').attr('data-price')*quantity;
	}
	console.log("price="+price);
	if(parseInt(quantity)>0){
		jQuery('#price').html('ca. '+price.toFixed(2).replace(".",",")+' &euro;*');
	}else{
		jQuery('#price').html('&nbsp;');
	}
}
function getOrderedProduct(){
	var orderedProductObj={
		id:'$ID',
		title:"$Title",
		variant01:jQuery("#variant01").val(),
		vac:getVac(),
		quantity:jQuery("#amount").val(),
		price:jQuery('#variant01  :selected').attr('data-price')
	}
	return orderedProductObj;
}
function getVac(){
	if($('#vac').length>0){
		if($('#vac').prop('checked')) { 
			return "on";
		}  else {
			return "off";
		}
	}else{
		return "notinuse";
	}
}
function getVariant01(){
	if($('#variant01').length>0){
		return jQuery("#variant01").val();
	}else{
		return "notinuse";
	}
}
function addToList(id,action){
	console.log("addToList");
		jQuery.ajax({
		url: "{$Link}/addToList?orderedProduct="+JSON.stringify(getOrderedProduct())+"&action="+action,
			success: function(data) {			
				dataAr=data.split("|");
				/*
					dataAr[0] = 0 -> error
					dataAr[0] = 1 -> ok
					dataAr[1] = error-code/product-number
					dataAr[2] = productsInBasket
				 */
				if(dataAr[0]!=0){
					$('#warenkorb_icon').html(dataAr[2]+" <i class='fas fa-shopping-bag' aria-hidden='true'></i>");
					//console.log("id="+id+" wurde dem Warenkorb hinzugefügt");
					$('#editFunction').css("display","block");
					$('#addFunction').css("display","none");
					if(dataAr[1]=="quantityrecalculated"){
						console.log("id="+id+" Die gewünschte Menge ist nicht mehr vorhanden. Wir haben die maximale Anzahl für Sie reserviert.");
					}else if(dataAr[1]=="added"){
						console.log("id="+id+" Das Produkt wurde Ihrem Warenkorb hinzugefügt.");
					}else if(dataAr[1]=="edited"){
						console.log("id="+id+" Die Produktmenge wurde angepasst");
					}
				}else{
					if(dataAr[1]=="stocklimitreached"){
						console.log("id="+id+" das Produkt befindet sich bereits im Warenkorb");
					}else if(dataAr[1]=="validation"){
						console.log("id="+id+" Es fehlen Angaben zum Produkt");
					}					
				}
				refreshSelectedProduct('refreshSameProduct');
			}
		});
	}
	function removeProductFromBasket(id){
		jQuery.ajax({
			url: "{$Link}/removeProductFromBasket?orderedProduct="+JSON.stringify(getOrderedProduct()),
			success: function(data) {
				if(parseInt(data)>0){
					$('#warenkorb_icon').html(data+' <i class="fas fa-shopping-bag">');
				}else{
					$('#warenkorb_icon').html('0 <i class="fas fa-shopping-bag">');
				}
				$('#amount').val(0);
				$('#editFunction').css("display","none");
				$('#addFunction').css("display","block");
			}
		});	
	}
	function getListCount(){
		jQuery.ajax({
			url: "{$Link}/getListCount",
			success: function(data) {
			if(parseInt(data)>0){
				$('#warenkorb_icon').html(data+' <i class="fas fa-shopping-bag">');
				}
			}
		});
	}
	function resetBasket(){
		jQuery.ajax({
			url: "{$Link}/ClearBasket",
			success: function(data) {
				$('#warenkorb_icon').html('0 <i class="fas fa-shopping-bag">');
			}
		});
	}
	
</script>