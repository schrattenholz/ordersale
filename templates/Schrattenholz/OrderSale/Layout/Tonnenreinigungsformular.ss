<style>
.felder{
list-style:none;width:140px;margin-right:2%;float:left;
}
.feld{
width:49%;
margin-right:2%;
float:left;
}
.feld input{
	width:90%;
}
.feld textarea{
	width:90%; 
}
#config_zahlung,#config_personen,#config_tonne{
display:none;
}
.link{
	color:#046bb2;
	text-transform:uppercase;
	cursor:pointer;
}
.link:hover{
	text-decoration:underline;
	}
.highlight{
	font-size: 1.22em;
	font-weight: 400;
	line-height: 1.375em;
	margin-bottom: 0.6815em;
}
</style>

 

  <script>
  
 var  steps=new Array("stadt","tonne","personen","zahlung","bestelluebersicht","bestellung");
 
 function toggleStep(step){
	 for(i=0;i<steps.length;i++){
		 if(steps[i]==step){
			jQuery("#config_"+steps[i]).css("display","block");
			jQuery("#step_"+steps[i]).addClass("highlight");
		 }else{
			jQuery("#config_"+steps[i]).css("display","none");
			jQuery("#step_"+steps[i]).removeClass("highlight");
		 }
	 } 
 }
  </script>
<section id="content" class="home clearfix">
 
   <div class="container">
        <div class="row">
          <aside class="span3" id="sidebar">
            <h1>Tonnenreinigung bestellen</h1>
            <p></p>
            <div class="callToActionBoxed">
              <div class="ctaText"> $Content</div>
            </div>
          </aside>
          <div class="span9">
            <div class="row da-thumbs">
			<h2>&nbsp;</h2>
				<% loop $Children %>
				  <article class="span3 isotopeItem men">
					<div class="boxWrapper"> <a href="$Link">
					  <section class="imgWrapper"> <img src="$Bild.URL" alt=""/> </section>
					  <div> <span class="iconWrapper iconLink"><i class="icon-plus"></i></span> </div>
					  </a>
					  <section class="boxContent">
						<h3>$Title </h3><h4>$Wheels R&auml;der</h4>
					  </section>
					</div>
				  </article>
				<% end_loop %>
              
            </div>
          </div>
        </div>
    </section>    
 
  
 
  

<script>
var tonnenValidation;
var gesamtPreis=0;
function getTonnen(){
	var tonnenObj={
	<% loop Tonnen %>
	$ID:{
		<% if $Has2Wheels  %>
		{$ID}2:{
			id:'$ID',
			wheels:'2',
			title:"$Title",
			anz_tonnen:jQuery("#anz_tonnen_2_$ID").val(),
			anz_reinigungen:jQuery("#anz_reinigungen_2_$ID").val(),
			zeit_woche:jQuery("#zeit_woche_2_$ID").val(),
			zeit_tag:jQuery("#zeit_tag_2_$ID").val(),
			preise:{<% loop $Preise2 %>$MinProductQuantity:"$Price",<% end_loop %>10000000:"0"}
		}
		<% end_if %>
		<% if $Has4Wheels  %>
		,{$ID}4:{
			id:'$ID',
			wheels:'4',
			title:"$Title",
			anz_tonnen:jQuery("#anz_tonnen_4_$ID").val(),
			anz_reinigungen:jQuery("#anz_reinigungen_4_$ID").val(),
			zeit_woche:jQuery("#zeit_woche_4_$ID").val(),
			zeit_tag:jQuery("#zeit_tag_4_$ID").val(),
			preise:{<% loop $Preise4 %>$MinProductQuantity:"$Price",<% end_loop %>10000000:"0"}			
		}
		<% end_if %>
	
	},
	<% end_loop %>
	}
		var key, obj, prop, owns = Object.prototype.hasOwnProperty;
	var errors=new Array();
	var tonnenImKorb=false;
	gesamtPreis=0;
	for (key in tonnenObj ) {
		if (owns.call(tonnenObj, key)) {
			obj = tonnenObj[key];
			for (prop in obj ) {
				// using obj.hasOwnProperty might cause you headache if there is
				// obj.hasOwnProperty = function(){return false;}
				// but owns will always work 
				if (owns.call(obj, prop)) {
					wert=calcPreis(prop,obj[prop]);
					if(wert && wert!="no") gesamtPreis+=wert;
					if(wert){
						if(wert=="no"){
						}else{
							tonnenImKorb=true;
							console.log(obj[prop].title+ 'mit '+prop.substr(prop.length - 1)+' Rädern: '+wert);
							//jQuery('#rechteSpalte').html(jQuery('#rechteSpalte').html()+obj[prop].title+ 'mit '+prop.substr(prop.length - 1)+' Rädern: '+wert);
							//return array(obj[prop].title,prop.substr(prop.length - 1),objTonne.anz_tonnen,wert);
						}						
					}else{
						console.log("Bitte überprüfen Sie Ihre Eingaben für "+obj[prop].title+ "mit "+prop.substr(prop.length - 1)+" Räden");
						errors.push(obj[prop].title+" mit "+prop.substr(prop.length - 1)+" Rädern");
					}
				}
			}
		}

	}
	if(errors.length>0){
		console.log("FEHLER");
		tonnenValidation="error";
		return errors;
	}else if(tonnenImKorb==false){	
		tonnenValidation="noproduct";
		return false;
	}else{
		console.log("Alles gut");
		tonnenValidation="ready";
		
		return tonnenObj;
	}
}
</script>