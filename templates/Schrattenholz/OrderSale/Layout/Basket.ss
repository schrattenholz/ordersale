  <section id="content" class="home clearfix">
  <section>
   <div class="container">
        <div class="row">
		

		  <div class="col-12">
		  
		  $Content
		  $Form
		  <% if $Basket.Products %>
		  <table class="table table-striped">
          <thead>
		 
            <tr>
              <th>Produkt </th>
              <th>Anzahl</th>
              <th>Menge</th>
			   <th>Vakuumieren</th>

			  <th></th>
			  <th></th>
            </tr>
          </thead>
		   <tbody>
		  <% loop $Basket.Products %>
		  <tr id="$ID_$Variant01_$Vac">
				
				  <td>$Title</td>
				  <td>$Quantity</td>
				  <td data-value="$Variant01.ID"><% if $Variant01.ShowContent %>$Variant01.Content<% else_if not $Variant01 %>$Top.formattedNumber($Amount) $Unit.Shortcode<% else %>$Variant01.getDisplayAmount<% end_if %></td>
				  <td data-value="$Vac">$VacReadable($Vac)</td>
				
				  
				  <td><a href="$Link?id=$ID&v=$Variant01.ID&vac=$Vac" title="Produkt bearbeiten"><i class="far fa-edit"></i></a></td>
				  <td><a href="javascript:removeProductFromBasket('$ID','$Variant01.ID','$Vac','$ID_$Variant01_$Vac');" title="Produkt aus dem Warenkorb entfernen"><i class="fas fa-trash-alt"></i></a></td>
				</tr>
		  <% end_loop %>

		 </tbody>	 
        </table>
		<div class="row"><div class="span12"><a class="btn" href="$LinkCheckoutAddress">Zur Bestellung</a>&nbsp;&nbsp;<a class="btn" href="javascript:clearBasket('{$Link}');">Warenkorb zurücksetzen</a></div></div>
		<% else %>
		<p>Es befinden sich momentan keine Produkte in Ihrem Warenkorb!</p>
		<a href="$LinkProductRoot">Zur Produkt-&Uuml;bersicht</a>
		
		<% end_if %>
          </div>
          
        </div>
		</div>
    </section>
    

    
    
  </section>
  
  <script>
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
  	function removeProductFromBasket(id,variant01,vac,anchor){
		jQuery.ajax({
			url: "{$Link}/removeProductFromBasket?id="+id+"&variant01="+variant01+"&vac="+vac,
			success: function(data) {
				if(parseInt(data)>0){
					$('#warenkorb_icon').html(data+' <i class="fas fa-shopping-bag">');
				}else{
					$('#warenkorb_icon').html('0 <i class="fas fa-shopping-bag">');
				}
				$('#'+anchor).remove();
			}
		});	
	}
  function getTonne(){
		var tonnenObj={
			
				
					id:'$ID',
					wheels:'$Wheels',
					title:"$Title",
					anz_tonnen:jQuery("#anz_tonnen").val(),
					anz_reinigungen:jQuery("#anz_reinigungen").val(),
					zeit_woche:jQuery("#zeit_woche").val(),
					zeit_tag:jQuery("#zeit_tag").val(),
					preise:{<% if $Preise %><% loop $Preise %>$MinProductQuantity:"$Price",<% end_loop %><% end_if %>10000000:"0"}
				
			
			
		}
		return tonnenObj;	
	}
	function addToList(id){
		jQuery.ajax({
			url: "{$Link}/addToList?tonne="+JSON.stringify(getTonne()),
			success: function(data) {			
				dataAr=data.split("|");
				/*
					dataAr[0] = 0 -> error
					dataAr[0] = 1 -> ok
					dataAr[1] = error-code/product-number
				 */
				if(dataAr[0]!=0){
					$('#warenkorb_icon').html(dataAr[1]+'<span>Warenkorb</span>');
					console.log("id="+id+" wurde dem Warenkorb hinzugefügt");
				}else{
					if(dataAr[1]=="double"){
						console.log("id="+id+" das Produkt befindet sich bereits im Warenkorb");
					}else if(dataAr[1]=="validation"){
						console.log("id="+id+" Es fehlen Angaben zum Produkt");
					}					
				}
			}
		});
	}
	function getListCount(){
		jQuery.ajax({
			url: "{$Link}/getListCount",
			success: function(data) {
			if(parseInt(data)>0){
				$('#warenkorb_icon').html(data+'<span>Warenkorb</span>');
				}
			}
		});
	}
	
  </script>