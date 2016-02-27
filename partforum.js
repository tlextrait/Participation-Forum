// Invoke the function in case the page loaded is already set in PartForum
changeForumType();

/**
* Functions controlling the UI in the creation form
*/

function changeForumType(){
	// Get the forum type
	var forumtype = document.getElementById("id_type");
	var selected = forumtype.options[forumtype.selectedIndex].value;
	
	// Read settings of form inputs
	var aggrtype = document.getElementById("id_assessed");
	var scale = document.getElementById("id_scale");
	
	// Get the url
	var URL = document.URL;
	var settingsPanel = (URL.indexOf("modedit")!=-1 && URL.indexOf("update")!=-1);
	
	var restrict = document.getElementsByName("ratingtime")[0];

	var to_day = document.getElementsByName("assesstimefinish[day]")[0];
	var to_month = document.getElementsByName("assesstimefinish[month]")[0];
	var to_year = document.getElementsByName("assesstimefinish[year]")[0];
	var to_hour = document.getElementsByName("assesstimefinish[hour]")[0];
	var to_min = document.getElementsByName("assesstimefinish[minute]")[0];
	
	var fr_day = document.getElementsByName("assesstimestart[day]")[0];
	var fr_month = document.getElementsByName("assesstimestart[month]")[0];
	var fr_year = document.getElementsByName("assesstimestart[year]")[0];
	var fr_hour = document.getElementsByName("assesstimestart[hour]")[0];
	var fr_min = document.getElementsByName("assesstimestart[minute]")[0];
	
	var groupmode = document.getElementById("id_groupmode");
	var groupvisible = document.getElementById("id_visible");
	
	if(selected=="participation"){
		// Hide and select Aggregate Type
		aggrtype.selectedIndex = 1;
		aggrtype.parentNode.parentNode.style.display = "none";
		// Hide and select Scale
		scale.removeAttribute('disabled');
		scale.selectedIndex = scale.options.length-10;
		scale.parentNode.parentNode.style.display = "none";
		// Hide and check time restriction
		restrict.removeAttribute('disabled');
		restrict.setAttribute('checked','checked');
		restrict.value = '1';
		restrict.parentNode.parentNode.parentNode.style.display = "none";
		// Enable to/from
		to_day.removeAttribute('disabled');
		to_month.removeAttribute('disabled');
		to_year.removeAttribute('disabled');
		to_hour.removeAttribute('disabled');
		to_min.removeAttribute('disabled');
		fr_day.removeAttribute('disabled');
		fr_month.removeAttribute('disabled');
		fr_year.removeAttribute('disabled');
		fr_hour.removeAttribute('disabled');
		fr_min.removeAttribute('disabled');
		// Hide group mode
		groupmode.selectedIndex = 0;
		groupmode.parentNode.parentNode.parentNode.parentNode.style.display = "none";
		// Change the date only if we are not in the settings panel
		if(!settingsPanel){
			// Set the assessment end date
			var d = new Date();
			d.setTime(d.getTime()*1 + (2*7-1)*24*3600*1000 + 24*3600*1000);
			// Set the year
			to_day.selectedIndex = d.getDate()-1;
			to_month.selectedIndex = d.getMonth();
			for(index = 0; index < to_year.length; index++) {
		   		if(to_year[index].value == d.getFullYear()){
			     	to_year.selectedIndex = index;
		     	}
		   	}
	   	}
	}else{
		restrict.removeAttribute('checked');
		restrict.parentNode.parentNode.parentNode.style.display = "";
		aggrtype.parentNode.parentNode.style.display = "";
		scale.parentNode.parentNode.style.display = "";
		groupmode.parentNode.parentNode.parentNode.parentNode.style.display = "";
	}
}
