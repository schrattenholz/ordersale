  <section id="content" class="home clearfix">
<form id="checkoutAddress" class="was-validated">
   <div class="container">
   <div class="row">
	   <div class="span12">
		   $Content
		  
	   </div>
   </div>

        <div class="row">
		
		  <div class="col-md-6">
		
		 <h3>Kontaktdaten:</h3>
				<div class="clearfix"></div>
				 <div class="form-group">
					<label>Firmenname</label>
					<input type="text" class="form-control" name="Firmenname" value="<% if $CheckoutAddress %>$CheckoutAddress.Firmenname<% else_if $CurrentMember %>$CurrentMember.Company<% end_if %>" <% if $CurrentMember %>readonly <% end_if %>/>					
				</div>
				 <div class="form-group">
					<label>Strasse/Nr*</label>
					<input  type="text" class="form-control" id="Strasse" name="Strasse" value="<% if $CheckoutAddress %>$CheckoutAddress.Strasse<% else_if $CurrentMember %>$CurrentMember.Street<% end_if %>" required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				 <div class="form-group">
					<label>PLZ*</label>
					<input type="text" class="form-control" id="PLZ"  name="PLZ" value="<% if $CheckoutAddress %>$CheckoutAddress.PLZ<% else_if $CurrentMember %>$CurrentMember.ZIP<% end_if %>"  required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				 <div class="form-group">
					<label>Ort*</label>
					<input type="text" class="form-control" id="Ort" name="Ort" value="<% if $CheckoutAddress %>$CheckoutAddress.Ort<% else_if $CurrentMember %>$CurrentMember.City<% end_if %>"   required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				<div class="clearfix"></div>
				<h3 class="clearfix">Ansprechpartner</h3>
				 <div class="form-group row" style="margin-left:1rem;margin-right:0;">
				
				<label class="custom-control-label col-6" for="01"><input type="radio" id="01" class="form-check-input" name="Anrede" required value="Frau" <% if $CheckoutAddress.Anrede=="Frau" || $CurrentMember.Gender=="Frau" %>checked <% if $CurrentMember %>readonly <% end_if %><% else %><% if $CurrentMember %> disabled<% end_if %><% end_if %> > Frau</label>
				
				<label class="custom-control-label col-6" for="02"><input type="radio" id="02" class="form-check-input" name="Anrede" required value="Herr" <% if $CheckoutAddress.Anrede=="Herr" || $CurrentMember.Gender=="Herr" %>checked <% if $CurrentMember %>readonly <% end_if %><% else %><% if $CurrentMember %> disabled<% end_if %><% end_if %> > Herr</label>
				</div>
				 <div class="form-group">
					<label>Nachname*</label>
					<input class="feld form-control"  type="text" id="Nachname" name="Nachname" value="<% if $CheckoutAddress %>$CheckoutAddress.Nachname<% else_if $CurrentMember %>$CurrentMember.Surname<% end_if %>" required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				 <div class="form-group">
					<label>Vorname*</label>
					<input class="feld last form-control" type="text" id="Vorname" name="Vorname" value="<% if $CheckoutAddress %>$CheckoutAddress.Vorname<% else_if $CurrentMember %>$CurrentMember.FirstName<% end_if %>" required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				 <div class="form-group">
					<label>Telefon*</label>
					<input class="feld form-control"  type="text" id="Telefon" name="Telefon" value="<% if $CheckoutAddress %>$CheckoutAddress.Telefon<% else_if $CurrentMember %>$CurrentMember.PhoneNumber<% end_if %>" required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				 <div class="form-group">
					<label>Email*</label>
					<input class="feld last form-control" type="text" id="Email" name="Email" value="<% if $CheckoutAddress %>$CheckoutAddress.Email<% else_if $CurrentMember %>$CurrentMember.Email<% end_if %>" required <% if $CurrentMember %>readonly <% end_if %>/>
				</div>
				
          </div>
		  
          <div class="col-md-6">
		   <h3 class="clearfix">Zus&auml;tzliche Anmerkungen</h3>
		   <br>
				<div class="feld">
					<textarea id="Anmerkungen"  class="form-control" name="Anmerkungen" cols="50" rows="4">$CheckoutAddress.Anmerkungen</textarea>
				</div>
          </div>
		  
        </div>
		<div class="row">
		<div class="span12" id="message">
			
		</div>
   </div>
  
		<div class="row">
			<div class="col-12">
				<button class="btn"  type="submit">Weiter zur Bestellübersicht</button>
				&nbsp;&nbsp; 
				<a class="btn" href="$LinkBasket">Zur&uuml;ck zum Warenkorb</a>
			</div>
		</div>
		</div>  
    </form>
  </section>
<script>



function checkoutAddress(nextLink,pageLink){
	var nextLink='$LinkCheckoutSummary';
	var pageLink='$Link';
	jQuery.ajax({
		url: pageLink+"/setCheckoutAddress?person="+JSON.stringify(jQuery('#checkoutAddress').serializeObject()),
		success: function(data) {
		window.location=nextLink; 
		console.log("checkoutAddress="+data);
		if(parseInt(data)>0){
			//$('#warenkorb_icon').html(data+'<span>Warenkorb</span>');
			}
		}
	});
}
</script>