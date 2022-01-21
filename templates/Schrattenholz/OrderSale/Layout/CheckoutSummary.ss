	<div class="container main" role="main">
        <div class="row fluid">
			<div class="col-12 fluid">
				$Content
				$Form
			</div>
		</div>
		  
		<div class="row fluid">
			<div class="col-12 fluid">
			<% if $Basket.Products %>
			<table class="table table-striped">
				<thead>
				<tr>
				  <th>Produkt</th>
				  <th>Anzahl</th>
				  <th>Menge</th>
				   <th>Vakuumieren</th>
				</tr>
				</thead>
				<tbody>
				<% loop $Basket.Products %>
				<tr>
					<td>$Title</td>
					<td>$Quantity</td>
					<td data-value="$Variant01.ID"><% if $Variant01.ShowContent %>$Variant01.Content<% else_if not $Variant01 %>$Amount $Unit.Shortcode<% else %>$Variant01.getDisplayAmount<% end_if %></td>
					<td data-value="$Vac">$VacReadable($Vac)</td>
				</tr>
				<% end_loop %>
				</tbody>
			</table>
			</div>
		</div>
<div class="row fluid">
			<div class="col-12 fluid">
				<h3>Anmerkungen zur Bestellung:</h3>
				$CheckoutAddress.Anmerkungen
			</div>
		</div>
		<form id="checkoutSummary" class="row fluid">
			<div class="col-md-6 pl-0">
				<h3>Kontaktdaten:</h3>
				<div class="clearfix"></div>
				 <div class="form-group">
					<label>Firmenname</label>
					<input type="text" class="form-control" name="Firmenname" value="$CheckoutAddress.Firmenname" disabled/>					
				</div>
				 <div class="form-group">
					<label>Strasse/Nr*</label>
					<input  type="text" class="form-control" id="Strasse" name="Strasse" value="$CheckoutAddress.Strasse" disabled/>
				</div>
				 <div class="form-group">
					<label>PLZ*</label>
					<input type="text" class="form-control" id="PLZ"  name="PLZ" value="$CheckoutAddress.PLZ" disabled />
				</div>
				 <div class="form-group">
					<label>Ort*</label>
					<input type="text" class="form-control" id="Ort" name="Ort" value="$CheckoutAddress.Ort"  disabled />
				</div>
			</div>
			<div class="col-md-6 pr-0">
				<h3 class="clearfix">Ansprechpartner:</h3>
				 <div class="form-group row" style="margin-left:1rem;margin-right:0;">
				<label class="custom-control-label col-6" for="01"><input type="radio" id="01" class="form-check-input" name="Anrede" value="Frau" disabled <% if $CheckoutAddress.Anrede=="Frau" %>checked<% end_if %>> Frau</label>
				
				<label class="custom-control-label col-6" for="02"><input type="radio" id="02" class="form-check-input" name="Anrede" value="Herr" disabled <% if $CheckoutAddress.Anrede=="Herr" %>checked<% end_if %>> Herr</label>
				</div>
				 <div class="form-group">
					<label>Nachname*</label>
					<input class="feld form-control"  type="text" id="Nachname" name="Nachname" value="$CheckoutAddress.Nachname"  disabled/>
				</div>
				 <div class="form-group">
					<label>Vorname*</label>
					<input class="feld last form-control" type="text" id="Vorname" name="Vorname" value="$CheckoutAddress.Vorname"  disabled/>
				</div>
				 <div class="form-group">
					<label>Telefon*</label>
					<input class="feld form-control"  type="text" id="Telefon" name="Telefon" value="$CheckoutAddress.Telefon"  disabled/>
				</div>
				 <div class="form-group">
					<label>Email*</label>
					<input class="feld last form-control" type="text" id="Email" name="Email" value="$CheckoutAddress.Email"  disabled/>
				</div>
			</div>
			<div class="col-md-12 fluid">
				  
					 <div class="form-group">
					<div class="form-check">
					 <input type="checkbox" name="agb" id="agb" style="margin-top:-2px" required />
					 <label for="agb" >Ich habe die <a href="{$BaseHref}$LinkAGB" >Allgemeinen Geschäftsbedingungen</a> gelesen und verstanden.</label>
					 </div></div>
					 <div class="form-group">
					<div class="form-check">
					 <input type="checkbox" name="datenschutz" id="datenschutz" style="margin-top:-2px" required />
					 <label for="datenschutz">Ich stimme zu, dass meine Angaben aus dem Bestellprozess zur Bearbeitung meiner Bestellung erhoben und verarbeitet werden. Die Daten werden ausschließlich für die Bearbeitung der Bestellung und der dazu nötigen Prozesse verwendet.</label> 
					 </div></div>
					 <p>Detaillierte Informationen zum Umgang mit Nutzerdaten finden Sie in unserer <a href="{$BaseHref}datenschutzerklärung" >Datenschutzerklärung</a>.</p>
					 
					<button id="bestellenBtn" type="submit" class="btn" value="Kostenpflichtig bestellen"  link="$LinkCheckoutFinal" >Verbindlich bestellen</button>
					&nbsp;&nbsp;<a class="btn" href="$LinkCheckoutAddress">Zur&uuml;ck</a> 
				
			</div>
		</div>
    </form>
	
		
		<% else %>
			Es befinden sich momentan keine Produkte in Ihrem Warenkorb!
		<% end_if %>
          </div>
          
        </div>    </div>  
  </section>
  
  <script>
  var order="$LinkCheckoutFinal";
  var link="$Link";




function checkoutSummary(nextLink,pageLink){
	var nextLink='$LinkCheckoutFinal';
	var pageLink='$Link';
	jQuery.ajax({
		url: pageLink+"/makeOrder",
		success: function(data) {
		window.location=nextLink; 
		console.log("checkoutSummary="+data);
		if(parseInt(data)>0){
			//$('#warenkorb_icon').html(data+'<span>Warenkorb</span>');
			}
		}
	});
}
</script>
  </script>
  