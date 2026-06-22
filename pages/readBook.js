var nameFound = "";
var lastTable = "";
var lastWord = "";
var command = "";
var getWhat = "";
var lastSelection = [];
var lastContext = "";
var lastCount = 0;
var lastVoice = "";
var lastUsually = "";
var lastID = 0;
var lastInput = "";
var lastVerb = "";
var prevVerb = "";
var prevWord = "";
var prevContext = "";
var Null = "";
var label;
var alias;
var count;
var voice;
var gender;
var prevParagraph = "";
var indexSelected;
var lastCmd;
var lastRegx;
var lastParagraph;
var countResponse;

// Step 1: Request Trigger and 5. Response Handling
async function changeRecipe(name, males, females, recipeName)
	{
	const url = `/TTS/api/findTableContainingName.php?name=${name}&males=${males}&females=${females}`; // URL points to the PHP file
	try
		{
		const response = await fetch(url);
		if (!response.ok)
			throw new Error(`Server responded with status: ${response.status}`);
		const userData = await response.json();
		table = JSON.stringify(userData.name);
//		alert(`jsonData = ${table}`);
		if (recipeName === 'normal')
			sql = `UPDATE ${table} SET recipe = '' WHERE label = '${label}'`;
		else
			sql = `UPDATE ${table} SET recipe = '${recipeName}' WHERE label = '${label}'`;
		accessDB(sql);
		return;
		}
	catch (error)
		{
		console.error("AJAX call failed:", error.message);
		return "Error loading data.";
		}
	}

// Example Call (non-blocking)
// loadUserData(1);

const delim = ':';
async function accessDB(sql)
	{
		var data = new FormData();

		// (A) CREATE FORM DATA
//console.log("COMMAND: " + sql);
//alert(sql);
		data.append("data", sql);

		// (B) INIT FETCH POST
		await fetch("readBookHandler.php", {method: "POST", body: data})

		// (C) RETURN SERVER RESPONSE AS TEXT
		.then((result) =>
		{
			if (result.status !== 200)
				{
					throw new Error("Bad Server Response");
				}
			console.log("RESULT");
			console.log(result);
		}, )
		// (D) SERVER RESPONSE
		.then((response) =>
		{
			console.log("RESPONSE");
			console.log(response);
			countResponse = response;
		})
		// (E) HANDLE ERRORS - OPTIONAL
		.catch((error) =>
		{
			console.log("ERROR");
			console.log(error);
		});
		document.getElementById('assignDiv').style.display = 'block';

		// (F) PREVENT FORM SUBMIT
		return false;
	}
lastOptionSelected = "";
function stepOne()
	{
		if (document.getElementById("wordBox").options.length < 3)
			{
				alert("There are no Unassigned Names.");
				return;
			}
		document.getElementById('stepOneDiv').style.display = 'block';
	}
function modifyNames(bookID)
	{
		lastID = bookID;
	}
function rememberName(selectedName)
	{
//$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender";
nameFound = selectedName;
		parms = selectedName.split('@@');
		label = parms[0];
		count = parms[1];
		voice = parms[2];
		gender = parms[3];

	}
function equateName(selectedName)
	{
//$value = "$name@@$table";
		parms = selectedName.split('@@');
		newlabel = parms[0];
		table = parms[1];
		sql = `UPDATE ${table} SET voice = '${voice}' WHERE label = '${newlabel}'`;
		accessDB(sql);
	}
function setRecipe(selection)
	{
//$value = "$name@@$theseMales@@$theseFemales";
	parms = selection.split('@@');
	recipeName = parms[0];
	theseMales = parms[1];
	theseFemales = parms[2];
//alert(`label = ${label}`);
	changeRecipe(label, theseMales, theseFemales, recipeName);
	
	var selectobject = document.getElementById("nameBox2");
	for (var i = 0; i < selectobject.length; i++)
		{
//alert(`looking for ${nameFound} finding ${selectobject.options[i].value}`);
		if (selectobject.options[i].value === nameFound)
			{
			parms = nameFound.split('@@');
			label = parms[0];
			selectobject.remove(i);
			newOption = document.createElement('option');
			selectobject.insertBefore(newOption, selectobject.options[i]);
			if (recipeName === "normal")
				selectobject.options[i].text =  `Manner for ${label} . . .`;
			else
				selectobject.options[i].text =  `Manner for ${label} . . . (Now ${recipeName})`;
			selectobject.options[i].value =  nameFound;
//alert(`setting display ${i} to  ${selectobject.options[i].text}`);
			break;
			}
		}
	}
function chosenPossibleName(word)
	{
//alert(word);
	list = document.getElementById("wordBox");

// $value = "$bookID@@$thisWord[label]@@$count@@$context";

	lastOptionSelected = word;
	word = lastOptionSelected.replace(/`/g, "'");
	lastSelection = word.split('@@');
	lastID = lastSelection[0];
	lastLabel = lastSelection[1];
	lastCount = lastSelection[2];
	lastContext = decodeURI(lastSelection[3]);
	showNameContext();
	lastInput = word;
	lastSelected = -1;
	}
function useMale(bookID)
	{
//	"cmd"				Sex
//	"paragraphNumber"		lastParagraph
//	"parm"			(not used)
//	"sex"				Male

		cmd = "Sex";
		parm = "";
		sex = "Male";
		table = "fixes" + bookID;
		y = document.getElementById('setNewParag');
		lastParagraph = y.value;

		if (lastParagraph === "")
			{
				alert("Paragraph number missing.");
				return;
			}
		sql = `INSERT INTO ${table} VALUES('${cmd}', '${lastParagraph}', '${parm}', '${sex}')`;
		accessDB(sql);
		if (confirm(`Causing a generic Male voice to be used in paragraph ${lastParagraph} in this book, when re-processed.\n\nFor best results, check names when you return.\n\nRe-process at this time?`))
			history.go(-1);		// go back one screen
		var selectobject = document.getElementById("problemBox");
		for (var i = 0; i < selectobject.length; i++)
			{
			if (selectobject.options[i].value === lastInput)
				{
				selectobject.remove(i);
				lastSelected = i;
				break;
				}
			}
	}
function useFemale(bookID)
	{
//	"cmd"				Sex
//	"paragraphNumber"		lastParagraph
//	"parm"			(not used)
//	"sex"				Female

		cmd = "Sex";
		parm = "";
		sex = "Female";
		table = "fixes" + bookID;
		y = document.getElementById('setNewParag');
		lastParagraph = y.value;

		if (lastParagraph === "")
			{
				alert("Paragraph number missing.");
				return;
			}
		sql = `INSERT INTO ${table} VALUES('${cmd}', '${lastParagraph}', '${parm}', '${sex}')`;
		accessDB(sql);
		if (confirm(`Causing a generic Female voice to be used in paragraph ${lastParagraph} in this book, when re-processed.\n\nFor best results, check names when you return.\n\nRe-process at this time?`))
			history.go(-1);		// go back one screen

		var selectobject = document.getElementById("problemBox");
		for (var i = 0; i < selectobject.length; i++)
			{
				if (selectobject.options[i].value === lastInput)
					{
						selectobject.remove(i);
						lastSelected = i;
						break;
					}
			}
	}
function useDefault(bookID)
	{
		// mostly does nothing, but marks fix as handled
//alert("Default");
		cmd = "handled";
		parm = "";
		sex = "Default";
		table = "fixes" + bookID;
		y = document.getElementById('setNewParag');
		lastParagraph = y.value;

		if (lastParagraph === "")
			{
				alert("Paragraph number missing.");
				return;
			}
		sql = `INSERT INTO ${table} VALUES('${cmd}', '${lastParagraph}', '${parm}', '${sex}')`;
		accessDB(sql);
		alert("Doing nothing, allowing the predicted voice to be used in paragraph " + lastParagraph + " in this book.");

		var selectobject = document.getElementById("problemBox");
		for (var i = 0; i < selectobject.length; i++)
			{
				if (selectobject.options[i].value === lastInput)
					{
						selectobject.remove(i);
						lastSelected = i;
						break;
					}
			}
	}
function enableMisc()
	{
		y = document.getElementById('modifyNames');
		y.style.display = 'block';
		y = document.getElementById('stepThreeDiv');
		y.style.display = 'block';
	}
lastSelected = -1;
function quote(n1)
	{
		return "\"" + n1 + "\"";
	}
function addQuotes2(n1, n2)
	{
		return quote(n1) + "," + quote(n2);
	}
function addQuotes3(n1, n2, n3)
	{
		return quote(n1) + "," + quote(n2) + "," + quote(n3);
	}
function changeVoice(where, sex)
	{
//alert(`where = ${where}, sex = ${sex}`);
	bookID = lastID;
	let newWords = "newWords" + bookID;
	count = 0;
	voice = "";

	sql = `CHANGEVOICE,${lastID},${lastLabel},${newWords},${where},${sex}`;
	accessDB(sql);
	var selectobject = document.getElementById("wordBox");
	for (var i = 0; i < selectobject.length; i++)
		{
		if (selectobject.options[i].value === lastInput)
			{
			selectobject.remove(i);
			lastSelected = i;
			break;
			}
		}
	var selectobject = document.getElementById("nameBox1");		// add to nameBox1 select box
	var option = document.createElement("option");
	option.text = `${lastLabel}`;
//$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender";
	option.value = `${lastLabel}@@${count}@@${voice}@@${gender}`;
	selectobject.add(option, selectobject.options[1]);

	var selectobject = document.getElementById("nameBox1a");		// add to nameBox1a select box
	var option = document.createElement("option");
	option.text = `${lastLabel}`;
	option.value = `${lastLabel}@@${count}@@${voice}@@${gender}`;
	selectobject.add(option, selectobject.options[1]);

	var selectobject = document.getElementById("nameBox2");		// add to nameBox2 select box
	var option = document.createElement("option");
	option.text = `${lastLabel}`;
	option.value = `${lastLabel}@@${count}@@${voice}@@${gender}`;
	selectobject.add(option, selectobject.options[1]);

	hideNameContext();
	}
function changeVoiceAll(where, sex)
	{
//alert(`where = ${where}, sex = ${sex}`);
		bookID = lastID;
		let newWords = "newWords" + bookID;
		count = 0;
		voice = "";
		gender = sex;

		if (confirm(`Move ${lastLabel} to ${where} permanently for all books?`))
			{
				if (where === 'verbs')
					sex = "(verb)";
// nouns not currently used anywhere
//		else if (where === 'nouns')
//			sex = "(noun)";
				else if (where === 'notName')
					sex = "(not)";
				sql = `CHANGEVOICE,${lastID},${lastLabel},${newWords},${where},${sex}`;
				accessDB(sql);
				var selectobject = document.getElementById("wordBox");
				for (var i = 0; i < selectobject.length; i++)
					{
						if (selectobject.options[i].value === lastInput)
							{
								selectobject.remove(i);
								lastSelected = i;
								break;
							}
					}
				var selectobject = document.getElementById("nameBox1");		// add to nameBox1 select box
				var option = document.createElement("option");
				option.text = `${lastLabel}`;
				//$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender";
				option.value = `${lastLabel}@@${count}@@${voice}@@${gender}`;
				selectobject.add(option, selectobject.options[1]);
				var selectobject = document.getElementById("nameBox2");		// add to nameBox2 select box
				var option = document.createElement("option");
				option.text = `${lastLabel}`;
				option.value = `${lastLabel}@@${count}@@${voice}@@${gender}`;
				selectobject.add(option, selectobject.options[1]);

				hideNameContext();
			}
	}
function newSubstitute(txt)
	{
		x = document.getElementById('newFor');
		txt1 = x.value;
		quoted = addQuotes2(txt1, txt);
		sql = "REPLACE INTO substituteWords (label, repl) VALUES(" + quoted + ")";
		accessDB(sql);
		if (confirm(`Re-Process after changing '${txt1}' to '${txt}'?\n\n(Applies to all books when next processed.)`))
			history.go(-1);
	}

function newConnective(txt)
	{
		lowered = txt.toLowerCase();
		quoted = addQuotes2(lowered, 1);
		sql = "REPLACE INTO connectives (label, usage, count) VALUES(" + quoted + ")";
		accessDB(sql);
		history.go(-1);
	}
function newVerb(txt)
	{
		type = "(verb)";
		lowered = txt.toLowerCase();
		quoted = addQuotes3(lowered, type, 1);
		sql = "REPLACE INTO verbs (label, usage, count) VALUES(" + quoted + ")";
		accessDB(sql);
		history.go(-1);
	}
function newMale(txt)
	{
		quoted = addQuotes2(txt, 1);
		sql = "REPLACE INTO males (label, count) VALUES(" + quoted + ")";
		accessDB(sql);
		history.go(-1);
	}
function newFemale(txt)
	{
		quoted = addQuotes2(txt, 1);
		sql = "REPLACE INTO females (label, count) VALUES(" + quoted + ")";
		accessDB(sql);
		history.go(-1);
	}
function displayNameContext(txt)
	{
		x = document.getElementById('context1');
		x.innerHTML = txt;
		x.style.display = 'block';
		y = document.getElementById('nameButtons');
		y.style.display = 'block';
	}
function hideNameContext()
	{
		y = document.getElementById('context1');
		y.style.display = 'none';
		y = document.getElementById('nameButtons');
		y.style.display = 'none';
		document.getElementById("title").scrollIntoView();
	}
function showNameContext()
	{
		let verb = "";
		if (lastContext === "")
			{
				lastContext = prevContext;
				displayNameContext("All instances of word '<b>" + lastLabel + "</b>' marked <b>bold.</b><br>"
				+ lastContext);
			}
		else
			{
				let regex = /%3D/ig;
				let context = lastContext.replace(regex, '=');
				lastContext = context;

				regex = /}}/ig;
				context = lastContext.replace(regex, '');
				lastContext = context;

				regex = /%24/ig;
				context = lastContext.replace(regex, '$');
				lastContext = context;

				regex = /%2C/ig;
				context = lastContext.replace(regex, ',');
				lastContext = context;

				regex = /%3F/ig;
				context = lastContext.replace(regex, '?');
				lastContext = context;

				regex = /\^/ig;
				context = lastContext.replace(regex, "<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;");
				lastContext = context;

				regex = /¤i¥/ig;
				context = lastContext.replace(regex, "");
				lastContext = context;

				regex = /¤\%2Fi¥/ig;
				context = lastContext.replace(regex, "");
				lastContext = context;

				regex = /%3A/ig;
				context = lastContext.replace(regex, ":");
				lastContext = context;

				regex = /%3B/ig;
				context = lastContext.replace(regex, ";");
				lastContext = context;

				regex = /¤/ig;
				context = lastContext.replace(regex, "<");
				lastContext = context;

				regex = /¥/ig;
				context = lastContext.replace(regex, ">");
				lastContext = context;

				regex = /%2F/ig;
				context = lastContext.replace(regex, "/");
				lastContext = context;

				let re = new RegExp(String.raw`\b${lastLabel}\b`, "g");		// \b's are to be on word boundary
				context = lastContext.replace(re, `<b>${lastLabel}</b>`);
				lastContext = context;

				lastVerb = verb;
				displayNameContext("All instances of word '<b>" + lastLabel + "</b>' marked <b>bold.</b><br>"
				+ lastContext);
				prevContext = lastContext;
				lastContext = "";
			}
	}
function setNewParag(num)
	{
		prevParagraph = lastParagraph;
		lastParagraph = num;
	}

function newregxCmd(cmd)
	{
		lastCmd = cmd;
		if (cmd === "All"
		|| cmd === "TTS"
		|| cmd === "AfterTTS"
		|| cmd === "Piper")
			;
		else
			alert("Undefined command: " + cmd);
	}
function newREGx(regx)
	{
		lastRegx = regx;
	}
function newRepl(repl, bookID)
	{
//	echo "<input type=text placeholder='All, TTS, or Piper' id=regxCmd onchange=newregxCmd(this.value)>";
//	echo "<input type=text placeholder='REGx' id=newREGx onchange=newREGx(this.value)>";
//	echo "<input type=text placeholder='Replacement' id=newRepl onchange=newRepl(this.value,$bookID)><br>";
		lastRepl = repl;
		sql = `REPLACE INTO REGX VALUES("${lastCmd}", "${lastRegx}", "${lastRepl}")`;
//		alert(`Replacing, in ${lastCmd} cases, the Regular Expression /${lastRegx}/ with "${lastRepl}"`);
		accessDB(sql);
		y = document.getElementById('regxCmd');
		y.value = '';
		y.placeholder = 'All, TTS, or Piper';
		y = document.getElementById('newREGx');
		y.value = '';
		y.placeholder = 'REGx';
		y = document.getElementById('newRepl');
		y.value = '';
		y.placeholder = 'Replacement';
	}
