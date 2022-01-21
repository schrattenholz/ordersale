<style>

.noBorder{
border:0;
}
h3, legend {
  font-size: 1.22em;
  font-weight: 400;
  line-height: 1.375em;
  margin-bottom: 0.6815em;
}
table {
  background-color: transparent;
  border-collapse: collapse;
  border-spacing: 0;
  max-width: 100%;
}
.table {
  margin-bottom: 20px;
  width: 100%;
}
.table th, .table td {
  border-top: 1px solid #dddddd;
  line-height: 20px;
  padding: 8px;
  text-align: left;
  vertical-align: top;
}
.table-striped tbody > tr:nth-child(2n+1) > td, .table-striped tbody > tr:nth-child(2n+1) > th {
  background-color: #f9f9f9;
}
.table-striped tbody tr:nth-child(2n+1) td, .table-striped tbody tr:nth-child(2n+1) th {
  background-color: #f5f5f5;
}
</style>
<h3>Guten Tag $CheckoutAddress.Anrede $CheckoutAddress.Vorname $CheckoutAddress.Nachname</h3>
<p>
dies ist die Bestellbestätigung für Ihre Bestellung bei hof-lehnmuehle.de.
</p>
<h3>Produkte auf der Bestellliste</h3>
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
		<h3>Anmerkungen zur Bestellung</h3>
		$CheckoutAddress.Anmerkungen
<h3>Ihre Daten</h3>
		
		 <div class="span4" style="margin-left:0;">
			<table class="table table-condensed">
				<thead>
				<tr>
				  <th>Kontaktdaten</th>
				  <th></th>
				  
				</tr>
			  </thead>
				<tbody>
					<tr><td class="noBorder">Firmenname:</td><td>$CheckoutAddress.Firmenname</td></tr>
					<tr><td class="noBorder">Strasse:</td><td class="noBorder">$CheckoutAddress.Strasse</td></tr>
					<tr><td class="noBorder">PLZ:</td><td class="noBorder">$CheckoutAddress.PLZ</td></tr>
					<tr><td class="noBorder">Ort:</td><td class="noBorder">$CheckoutAddress.Ort</td></tr>
				</tbody>
			</table>
          </div>
          <div class="span4">
		  <table class="table table-condensed">
			<thead>
				<tr>
				  <th>Ansprechpartner</th>
				  <th></th>
				  
				</tr>
			  </thead>
				<tbody>
					<tr><td width="20%">Vorname:</td><td width="80%">$CheckoutAddress.Vorname</td></tr>
					<tr><td class="noBorder">Nachname:</td><td class="noBorder">$CheckoutAddress.Nachname</td></tr>
					<tr><td class="noBorder">Telefon:</td><td class="noBorder">$CheckoutAddress.Telefon</td></tr>
					<tr><td class="noBorder">Email:</td><td class="noBorder">$CheckoutAddress.Email</td></tr>
				</tbody>
			</table>

          </div>
		  <div>
		  $OrderConfig.EmailSignature
		  
		  </div>
