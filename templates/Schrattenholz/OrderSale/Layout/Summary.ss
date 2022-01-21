<style>

.noBorder{
border:0;
}
</style>
<h3>Produkte auf der Bestellliste</h3>
<table class="table table-striped">
          <thead>
            <tr>
              <th>Tonne</th>
              <th>R&auml;der</th>
              <th>Anzahl</th>
			   <th>Reinigungen / Jahr</th>
              <th>Intervall</th>
			  <th style="text-align: right;">Preis</th>
            </tr>
          </thead>
          <tbody>
			<% loop Tonnen %>
				<tr>
				
				  <td>$Title</td>
				  <td>$Wheels</td>
				  <td>$Amount</td>
				  <td>$Number</td>
				  <td>$Interval</td>
				  <td style="text-align: right;">$Price</td>
				</tr>
			<% end_loop %>
			<tr>
				  <td></td>
				  <td></td>
				  <td></td>
				  <td></td>
				  <td style="text-align: right;">Preis gesamnt</td>
				  <td style="text-align: right;">$TotalPrice</td>
				</tr>
			<tr>
				  <td></td>
				  <td></td>
				  <td></td>
				  <td></td>
				  <td style="text-align: right;">inkl. MwSt(19%)</td>
				  <td style="text-align: right;">$TotalTax</td>
			</tr>
          </tbody>	 
        </table>
		
<h3>Angaben zum Auftraggeber</h3>
		<% loop $Person %>
		 <div class="span4" style="margin-left:0;">
			<table class="table table-condensed">
				<thead>
				<tr>
				  <th>Kontaktdaten</th>
				  <th></th>
				  <th></th>
				   <th></th>
				  <th></th>
				  <th></th>
				</tr>
			  </thead>
				<tbody>
					<tr><td>Kundenart:</td><td>$Kundenart</td></tr>
					<tr><td class="noBorder">Firmenname:</td><td>$Firmenname</td></tr>
					<tr><td class="noBorder">Strasse:</td><td class="noBorder">$Strasse</td></tr>
					<tr><td class="noBorder">PLZ:</td><td class="noBorder">$PLZ</td></tr>
					<tr><td class="noBorder">Ort:</td><td class="noBorder">$Ort</td></tr>
				</tbody>
			</table>
          </div>
          <div class="span4">
		  <table class="table table-condensed">
			<thead>
				<tr>
				  <th>Ansprechpartner</th>
				  <th></th>
				  <th></th>
				   <th></th>
				  <th></th>
				  <th></th>
				</tr>
			  </thead>
				<tbody>
					<tr><td>Anrede:</td><td>$Anrede</td></tr>
					<tr><td class="noBorder">Vorname:</td><td>$Vorname</td></tr>
					<tr><td class="noBorder">Nachname:</td><td class="noBorder">$Nachname</td></tr>
					<tr><td class="noBorder">Telefon:</td><td class="noBorder">$Telefon</td></tr>
					<tr><td class="noBorder">Email:</td><td class="noBorder">$Email</td></tr>
				</tbody>
			</table>

          </div>
<div class="span4">
		  <table class="table table-condensed">
			<thead>
				<tr>
				  <th>Abweichende Reinigungsanschrift</th>				  
				</tr>
			  </thead>
				<tbody>
					<tr><td>$reinigungsanschrift</td></tr>
				</tbody>
			</table>
 <table class="table table-condensed">
			<thead>
				<tr>
				  <th>Abweichende Rechnungsanschrift</th>				  
				</tr>
			  </thead>
				<tbody>
					<tr><td>$rechnungsanschrift</td></tr>
				</tbody>
			</table>
          </div>
		
		 <% end_loop %>
		 <div class="span12" style="margin-left:0;">
		 <h3>Zahlungsart: $Zahlungsart</h3>
		 
	
		</div>
		 <div class="span12" style="margin-left:0;">
		 <p>
		 <input type="checkbox" name="agb" id="agb" style="margin-top:-2px" />
		 <span class="checkbox">Ich habe die <a href="{$BaseHref}agb" target="_blank">Allgemeinen Geschäftsbedingungen</a> gelesen und verstanden.</span>
		 </p>
	<input type="button" id="bestellenBtn" class="btn" value="Kostenpflichtig bestellen" disabled />
	</div>
	<script>
	jQuery(document).ready(function() {
		bindAGB();	
		jQuery('#bestellenBtn').unbind().bind('click',function(){
			ajax('checkout');
		});
	});
	function bindAGB(){
		jQuery('#agb').unbind().bind('click',function(){	
			var ref=jQuery("#bestellenBtn");

			var attr = ref.attr('disabled');
			// For some browsers, `attr` is undefined; for others,
			// `attr` is false.  Check for both.
			if (typeof attr !== typeof undefined && attr !== false) {
				
				ref.removeAttr("disabled");
				console.log("aus");
			}else{
				ref.attr("disabled","disabled");
				console.log("an");
			}
		});	
	}
	
	</script>