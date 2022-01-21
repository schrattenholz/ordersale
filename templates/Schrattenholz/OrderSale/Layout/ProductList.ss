<div class="container main" role="main">
	<% if $Content %>
		<div class="row fluid <% if $SidebarObjects %>justify-content-md-end<% else %>justify-content-md-center <% end_if %> py-4 ratio_$Ratio">
			<article class="col-12 <% if $SidebarObjects %>col-12 col-md-6  pt-3 pl-lg-0 ratio_left<% else %> <% end_if %>">
			<% if $URLSegment=="home" || $URLSegment=="" %>
			<% if $Headline %><h1>$Headline</h1><% else %><h1>$MenuTitle.XML</h1><% end_if %>
			<% else %>
			<% if $Headline %><h2>$Headline</h2><% end_if %>
			<% end_if %>
				$Content
				$Form
			</article>
			<% if $SidebarObjects %>
			<aside class="col-12 col-md-6 pr-lg-0 pt-3 ratio_right">
				<% loop SidebarObjects.Sort('SortID') %>
				$renderIT
				
				<% end_loop %>
			</aside>
			<% end_if %>
		</div>
	<% end_if %>
	<% if $IntroText  %>
	<div class="row justify-content-md-center pb-4">
		<article class="boxwidth col-12 pb-4">
			$IntroText 
		</article>
	</div>
	<% end_if %>
	<div class="row justify-content-start pb-4 pt-5 orderlist">
		<% if $Children %>				
			<% loop $Children.Sort('Date','DESC') %>
				<article class="card col-12 col-sm-6 col-md-4 ">
				<div class="cardlink">
					<a style="textdecoration:none;" href="$Link"><img class="card-img" src="<% if $ProductImages %>$ProductImages.First.Fill(400,266).URL<% else_if $MainImage %>$MainImage.Fill(400,266).URL<% else %>$BaseHref/resources/vendor/schrattenholz/order/theme/images/default.jpg<% end_if %>" alt="$MainImage.Filename"></a>
					<% if not $Top.HideLabel %>
					<!--
					<div class="tag">
						$DateSummary
					</div>
					-->
					<% end_if %>
					<div class="card-block p-0">
						<h5 class="card-title pt-2 rw_blau"><% if not $Top.NotClickable %><a style="textdecoration:none;" href="$Link"><% end_if %>$MenuTitle.XML<% if not $Top.NotClickable %></a><% end_if %></h5>
						<p class="card-text"><% if $Top.ShowWholeContent %>$Content<% else %><% if $TeaserText %>$TeaserText<% else_if $CuttedText(100) %>$CuttedText(100)<% else %><% end_if %><% end_if %></p>
					</div>
				</div>
				</article>
			<% end_loop %>
		<% else %>
		<div class="row mb-4" >
				
			<article class="card col-12  pl-0 pr-0 pl-sm-0 pr-sm-3">
			$NoEntryText 
			</article>
		
		</div>
		<% end_if %>
					
			<% if $Form %>
				$Form
			<% end_if %>
		</div>
	</div>

