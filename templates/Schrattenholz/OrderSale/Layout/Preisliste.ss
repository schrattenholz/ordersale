  <section id="content" class="home clearfix">
  <section>
   <div class="container">
        <div class="row">
          <div class="span8">
		  $Content
		  <table class="table">
          <thead>
            <tr>
			  <th></th>
              <th>Beh&auml;lter</th>
              <th>Art</th>
              <th>Preis pro Stück</th>   
            </tr>
          </thead>
          <tbody>
            
           
         
		  
		  <% loop Preisliste %>
			<tr>
				<td>$Bild</td>
              <td>$Wheels R&auml;der</td>
              <td>$Title</td>
			  <td><table><% loop $Preise %><tr <% if $Pos!=1 %>style="border-top:1px solid #dddddd;"<% end_if %>><td style="padding:0;border:none;"><%if $Last %>ab $MinProductQuantity<% else %>bis $MaxProductQuantity<% end_if %> Stk. <p>$Price €</p></td>
			  <% end_loop %></table></td>
            
            </tr>
		  <% end_loop %>
		   </tbody>
        </table>
          </div>
          <div class="span4">
		  
		  
		   <% if Widgets %>
		   
			   <% loop Widgets.Sort("SortOrder") %>
					$Content($Top.ID)
					
					<% if $Last %><% else %><div style="border-bottom: 1px dotted; height: 20px; color: rgb(238, 238, 238); margin-bottom: 40px;">&nbsp;</div><% end_if %>
			   <% end_loop %>
		   
		   <% end_if %>
          </div>
        </div>
		</div>
    </section>
    

    
    
  </section>