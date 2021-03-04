<section id="product" class="home clearfix pt-md-4 pb-md-6">
   <div class="container">
		<div class="row">
			<div class="col-md-4">
			<% if $ProductImages %>
				<div class="imgWrapper"> <img src="$ProductImages.First.Fill(400,300).URL" alt="$ProductImages.First.Filename"/></div>
			<% else_if $Content %>
				$Content
			<% end_if %>S
			</div>
			<div class="offset-md-1 col-md-7">
				<!--<nav id="projectsNav">
				  <ul class="pager">
					<li><a href=""><i class="icon-left-open-mini"></i>&nbsp;Previous</a></li>
					<li><a href="">Next&nbsp;<i class="icon-right-open-mini"></i></a></li>
				  </ul>
				</nav>-->
				<!--<h2 class="standardTitle"><span>$Title</span><span style="float:right;"><a href="$LinkProductRoot">Zur &Uuml;bersicht</a></h2>-->
				<% if $Addons %><h3><% loop $Addons %>$Title<% if not $Last %>, <% end_if %><% end_loop %> | <% loop $Ingredients %>$Title<% if not $Last %>, <% end_if %><% end_loop%></h3><% end_if %>
				<form id="product-config">
					<div class="row no-gutters">
						<div class="col-md-12">
							<% if $Preise && $ShowPricingTable %>
								Preistabelle
								<table class="table table-striped">
								<% loop $Preise %><tr><td>$DisplayAmount: $Top.formattedNumber($Price) &euro;/Stk.</td></tr><% end_loop %>
								</table>
								<% else %>
								<h3>$formattedNumber($Price) &euro; / kg *</h3>
								<% if $Amount>0 %><p>Menge pro Verpackungseinheit: $formattedNumber($Amount)$Unit.Shortcode</p><% end_if %>
							<% end_if %>
						</div>
					</div>
					<div class="row no-gutters">
					<% if $Preise %>
						<div class="col-md-6 pr-md-2">
							
							<h5>Menge pro Einheit</h5>
							<div class="selectric-wrapper">
								<select id="variant01"  style="width:100%;" onchange="refreshSelectedProduct()">
								<% loop $Preise %>
									<option value="$ID" data-price="$Price" <% if $Top.loadSelectedParameters.Variant01==$ID %> selected="selected"<% else_if $First %> selected="selected"<% end_if %>><% if $ShowContent %>$Content<% else %>$DisplayAmount<% end_if %></option>
								<% end_loop %>
								</select>
							</div>
							
							
						</div>
						<% end_if %>
						<div class="<% if $Preise %>col-md-6<% else %>col-md-12<% end_if %>">
							<h5>Anzahl</h5>
							<div class="quantity position-relative clearfix d-inline-block align-top">
								<input id="amount" type="number" min="0" max="100" step="1" onchange="calculatePrice(this.value)" value="<% if $loadSelectedParameters.Quantity %>$loadSelectedParameters.Quantity<% else %>0<% end_if %>">
							</div>
						</div>
						<% if $Vacuum %>
					<div class="col-md-12 ">
						<input type="checkbox" class="styledcheckbox" id="vac" <% if $Top.loadSelectedParameters.Vac=="on" %>checked="checked"<% end_if %> onchange="refreshSelectedProduct()" /><label for="vac">Die einzelnen Stücke vakuumieren (Aufpreis pro Stück 1,00€)<label> 
					</div>
					<% end_if %>
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
							<div class="col-12">
								<a class="btn" href="$LinkBasket">Warenkorb &ouml;ffnen</a>
							</div>
						</div>
					</div>
					</form>
					<a href="javascript:resetBasket();">Warenkorb leeren</a>
        </div>
		<% if $ProductImages && $Content %>
		<div class="row">
			<div class="col-6">
				$Content
			</div>
		</div>
		<% end_if %>
    </div>
</section>
<script>
function refreshSelectedProduct(){
console.log("refreshSelectedProduct");
		if($('#vac').prop('checked')) { 
			var vac="on";
		}  else {
			var vac="off";
		} 
		jQuery.ajax({
			url: "{$Link}/checkIfProductInBasket?id=$ID&variant01="+jQuery("#variant01").val()+"&vac="+vac,
			success: function(data) {
				if(parseInt(data)>0){
					$('#amount').val(data);
					//calculatePrice(data)
					$('#editFunction').css('display','block');
					$('#addFunction').css('display','none');
				}else{
					$('#amount').val(0);
					//calculatePrice(data)
					$('#editFunction').css('display','none');
					$('#addFunction').css('display','block');
				}
			}
		});	
}
function calculatePrice(quantity){
	var price=jQuery('#variant01  :selected').attr('data-price')*quantity;
	console.log("price="+price);
	jQuery('#price').html(price.toFixed(2)+' &euro;');
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
				 */
				if(dataAr[0]!=0){
					$('#warenkorb_icon').html(dataAr[1]+" <i class='fas fa-shopping-bag' aria-hidden='true'></i>");
					//console.log("id="+id+" wurde dem Warenkorb hinzugefügt");
					$('#editFunction').css("display","block");
					$('#addFunction').css("display","none");
				}else{
					if(dataAr[1]=="double"){
						//console.log("id="+id+" das Produkt befindet sich bereits im Warenkorb");
					}else if(dataAr[1]=="validation"){
						//console.log("id="+id+" Es fehlen Angaben zum Produkt");
					}					
				}
			}
		});
	}
	function removeProductFromBasket(id){
		jQuery.ajax({
			url: "{$Link}/removeProductFromBasket?id=$ID&variant01="+jQuery("#variant01").val()+"&vac="+getVac(),
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