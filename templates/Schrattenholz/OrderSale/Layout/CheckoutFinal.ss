<div class="container-fluid main" role="main">
	<div class="hellgrau <% if $HasQuadCarousel %> <% end_if %>row fluid <% if $ContentObjects || $Form  %>justify-content-md-end<% else %>justify-content-md-center <% end_if %> <% if not $HeadlineFullWidth %>py-4<% else %><% end_if %> ratio_$Ratio">
		<article class="col-12 <% if $ContentObjects  || $Form %>col-12 col-md-6  pt-3 pl-lg-0 ratio_left<% else %>boxwidth <% end_if %>">
		<p>Vielen Dank f&uuml;r Ihre Bestellung.</p>
		<p>Sie erhalten in K&uuml;rze eine Bestellbest&auml;tigung an die von Ihnen angegebene Email-Adresse $CheckoutAddress.Email</p>
		<p>&nbsp;</p>
		<p>Beste Gr&uuml;&szlig;e<br>das Team des Hof Lehnmühle</p>
		</article>
		
	</div>
</div>
	