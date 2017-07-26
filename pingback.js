/*
	Copyright (C) 2009,2010 GungHo Technologies LLC
	released under GPLv3 - please refer to the file copyright.txt
*/

// Checks return status from the xmlHttpRequest and displays an error if found
function pb11_checkError(response, postData)
{
	if(/ERROR/.test(response.responseText))
	{
		return [false, response.responseText];
	}
	return [true, 'xxx'];
}

// Checks the current progress status and displays in embedded div
function pb11_status(data, textStatus)
{
	var _postData2 = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'status'};
	if(data == '100')
	{
		if(jQuery('#pb11_status').length)
		{
			jQuery('#pb11_status').html('Progress:&nbsp;DONE');
		}
	}
	else
	{
		if(jQuery('#pb11_status').length)
		{
			jQuery('#pb11_status').html('Progress:&nbsp;' + data + '%');
			jQuery.post(ajaxurl, _postData2, pb11_status, 'text');
		}
	}
}

jQuery(document).ready(function($) {
// Main function start
var _postData = {action:'pingbacker',_wpnonce:$('#pingbacker_nonce').val(),grid:'tags'};
var _postData1 = {action:'pingbacker',_wpnonce:$('#pingbacker_nonce').val(),grid:'results'};
var _postData2 = {action:'pingbacker',_wpnonce:$('#pingbacker_nonce').val(),grid:'status'};

$('#pb11_tags').jqGrid(
{
	url:ajaxurl,
	editurl:ajaxurl,
	datatype: 'xml',
	mtype: 'POST',
	postData: _postData,
	colNames:['Tag'],
	colModel:
	[
		{name:'tag',index:'tag',editable:true},
	],
	autowidth: true,
	loadui: 'block',
	loadtext: 'Processing...',
	pager: $('#pb11_tags_p'),
	pgbuttons: false,
	pginput: false,
	sortname: 'tag',
	viewrecords: true,
	sortorder: 'asc',
	rowNum: -1,
	caption: 'Tags to use for pingback search',
	recordpos: 'right',
	recordtext: '{2} tags',
	multiselect: true,
	hidegrid: false,
	loadError: function(xhr,status,error) { alert(xhr.responseText); }
});
$('#pb11_tags').jqGrid('navGrid','#pb11_tags_p',
{
	search:false,
	edittext:'Edit&nbsp;',
	deltitle:'Remove Tag',
	addtitle:'Add Tag',
	edittitle:'Edit Tag',
	refreshtitle:'Refresh Tags',
	deltext:'Remove&nbsp;',
	addtext:'Add&nbsp;',
	refreshtext:'Refresh&nbsp;'
},{
	afterSubmit:pb11_checkError,editData:_postData
},{
	afterSubmit:pb11_checkError,editData:_postData
},{
	afterSubmit:pb11_checkError,delData:_postData
});

$('#pb11_results').jqGrid(
{
	url:ajaxurl,
	editurl:ajaxurl,
	datatype: 'xml',
	mtype: 'POST',
	colNames:['URL', 'Title', 'Google PR', 'Ext.Links'],
	colModel:
	[
		{name:'url',index:'url', width:100},
		{name:'title',index:'title', width:100},
		{name:'googlepr',index:'googlepr', width:50, align:'center'},
		{name:'ext_links',index:'ext_links', width:50, align:'right'}
	],
	postData: _postData1,
	rowNum: -1,
	autowidth: true,
	pager: $('#pb11_results_p'),
	pgbuttons: false,
	pginput: false,
	sortname: 'id',
	viewrecords: true,
	sortorder: 'desc',
	caption: 'Found blogs referring the tags',
	loadui: 'block',
	loadtext: '<span id="pb11_status">Processing...</span>',
	recordpos: 'right',
	recordtext: '{2} blogs',
	multiselect: true,
	hidegrid: false,
	loadError: function(xhr,status,error)
	{
		if (xhr.responseText.length)
		{
			alert(xhr.responseText);
		}
	},
	loadBeforeSend: function(xhr)
	{
		if($('#pb11_status').length)
		{
			$('#pb11_status').html('Processing...');
		}
		$.post(ajaxurl, _postData2, pb11_status, 'text');
	}
});
$('#pb11_results').jqGrid('navGrid','#pb11_results_p',
{
		search:false,
		edit:false,
		add:false,
		deltitle:'Remove URL from list',
		deltext:'Remove&nbsp;',
		refreshtext:'Refresh',
		refreshtitle:'Refresh URL List/Process new tags'
},{},{},{
	afterSubmit:pb11_checkError,delData:_postData1
});

// Function end
});
