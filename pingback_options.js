/*
	Copyright (C) 2010 GungHo Technologies LLC
	released under GPLv3 - please refer to the file copyright.txt
*/

var globAbort = false;

// Fix for IE lack of trim function
if(typeof String.prototype.trim !== 'function') {
  String.prototype.trim = function() {
    return this.replace(/^\s+|\s+$/, '');
  }
}

// Checks return status from the xmlHttpRequest and displays an error if found
function pb11_checkError(response, postData)
{
	if(/ERROR/.test(response.responseText))
	{
		return [false, response.responseText];
	}
	return [true, ''];
}

function pb11_mass_cancel()
{
	jQuery('#pb11_background').fadeTo('fast', 0.0, function()
	{
		jQuery('#pb11_window').hide();
		jQuery('#pb11_background').hide();
	});
	return true;
}

function pb11_mass_processing(response, data)
{
	globAbort = true;
	if(/ERROR/.test(response.responseText))
	{
		alert(response.responseText);
	}
	window.location.reload();
}

function pb11_mass_status(response, data)
{
	var _postData1 = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'mass_status'};
	if (/ERROR/.test(response.responseText))
	{
		alert(response.responseText);
		globAbort = true;
		return false;
	}
	result = response.responseText.split(':');
	jQuery('#pb11_progressbartxt').html(result[0]);
	jQuery('#pb11_progressbarext').html(result[1]);
	if (!globAbort && response.responseText != '100')
	{
		jQuery.ajax({url:ajaxurl, data:_postData1, success:pb11_mass_status,
			error:pb11_mass_status, dataType:'xml', type:'POST'});
	}
}

function pb11_mass_process(check)
{
	var _postData = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'mass_process',
	data:escape(jQuery('#pb11_masslist').getGridParam('selarrrow'))};
	var _postData1 = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'mass_status'};

	// Do nothing if no selection
	if (!_postData.data.length)
	{
		return true;
	}
	if (check == true)
	{
		_postData.grid = 'chk_mass_process';
		_postData.remove = jQuery('#pb11_verify_delchk').attr('checked');
	}
	jQuery('#pb11_window').hide();
	jQuery('#pb11_progress').css('left',
		(jQuery(document).width() - jQuery('#pb11_progress').outerWidth(true)) / 2 + 'px');
	jQuery('#pb11_progress').css('top',
		(jQuery(document).height() - jQuery('#pb11_progress').outerHeight(true)) / 2 + 'px');
	jQuery('#pb11_progressbar').css('width', jQuery('#pb11_progress').outerWidth(true) - 10 + 'px');
	jQuery('#pb11_progressbar').css('left',
		(jQuery(document).width() - jQuery('#pb11_progressbar').outerWidth(true)) / 2 + 'px');
	jQuery('#pb11_progressbar').css('top',
		(jQuery(document).height() - jQuery('#pb11_progressbar').outerHeight(true)) / 2 + 'px');
	jQuery('#pb11_progress').css('opacity', 0.0);
	jQuery('#pb11_progress').show();
	jQuery('#pb11_progress').fadeTo('fast', 1.0, function()
	{
		jQuery('#pb11_progressbar').show();
		globAbort = false;
		jQuery.ajax({url:ajaxurl, data:_postData, success:pb11_mass_processing,
			error:pb11_mass_processing, dataType:'xml', type:'POST'});
		jQuery.ajax({url:ajaxurl, data:_postData1, success:pb11_mass_status,
			error:pb11_mass_status, dataType:'xml', type:'POST'});
	});
	return true;
}

function pb11_mass_trigger(check)
{
	var _postData = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'mass'};

	jQuery('#pb11_background').css('opacity', 0.0);
	jQuery('#pb11_background').show();
	jQuery('#pb11_background').fadeTo('fast', 0.5);
	jQuery('#pb11_window').css('left',
		(jQuery(document).width() - jQuery('#pb11_window').outerWidth(true)) / 2 + 'px');
	jQuery('#pb11_window').css('top',
		(jQuery(document).height() - jQuery('#pb11_window').outerHeight(true)) / 2 + 'px');
	jQuery('#pb11_window').show();
	jQuery('#pb11_background').height(jQuery(document).height());
	jQuery('#pb11_verify_delchk').attr('checked', null);
	if (check == false)
	{
		mass_capt = '[Update] Blogs to process with pingbacks';
		jQuery('#pb11_verify_delete').css('display', 'none');
		jQuery('#pb11_mass_process').click(function() {pb11_mass_process(false);});
	}
	else
	{
		mass_capt = '[Verify] Blogs to verify pingbacks for';
		jQuery('#pb11_verify_delete').css('display', 'block');
		jQuery('#pb11_mass_process').click(function() {pb11_mass_process(true);});
		_postData.grid = 'chk_mass';
	}
	if (!jQuery('#pb11_masslist').html().length)
	{
		jQuery('#pb11_masslist').jqGrid(
		{
			url:ajaxurl,
			editurl:ajaxurl,
			datatype: 'xml',
			mtype: 'POST',
			postData: _postData,
			colNames:['Blog Title', 'Modified date'],
			colModel:
			[
				{name:'title',index:'title',width:360},
				{name:'date',index:'modified',width:80}
			],
			autowidth: true,
			height: jQuery('#pb11_window').innerHeight() - 160,
			loadui: 'block',
			loadtext: 'Processing...',
			pager: jQuery('#pb11_masslist_p'),
			pgbuttons: false,
			pginput: false,
			sortname: 'modified',
			viewrecords: true,
			sortorder: 'desc',
			rowNum: -1,
			caption: mass_capt,
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
			}
		});
		jQuery('#pb11_masslist').jqGrid('navGrid','#pb11_masslist_p',
		{
			search:false,
			add:false,
			del:false,
			edit:false,
			refreshtitle:'Refresh Blog List',
			refreshtext:'Refresh&nbsp;'
		});
	}
	else
	{
		jQuery('#pb11_masslist').jqGrid('setCaption', mass_capt).jqGrid('setGridParam', {postData: _postData}).trigger("reloadGrid");
	}
	return true;
}

function pb11_notify_callback(response, data, error)
{
	if(/ERROR/.test(response.responseText))
	{
		alert(response.responseText);
	}
	window.location.reload();
}

// Notification list
function pb11_notify_trigger(obj)
{
	var _postData = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),grid:'notify_email',
		name:jQuery('#pb11_name').val(),email:jQuery('#pb11_email').val()};
	var _postData1 = {action:'pingbacker',_wpnonce:jQuery('#pingbacker_nonce').val(),code:jQuery('#pb11_code').val(),grid:'notify_submit'};

	if (jQuery(obj).val() != 'Register')
	{
		if (_postData1.code.trim() == '')
		{
			alert("Please enter your access code");
			jQuery('#pb11_code').focus();
			return true;
		}
		// Confirm code
		jQuery.ajax({url:ajaxurl, data:_postData1, success:pb11_notify_callback,
			error:pb11_notify_callback, dataType:'xml', type:'POST'});
	}
	else
	{
		if (_postData.name.trim() == '')
		{
			alert("Please enter your name");
			jQuery('#pb11_name').focus();
			return true;
		}
		if (_postData.email.trim() == '')
		{
			alert("Please enter your email");
			jQuery('#pb11_email').focus();
			return true;
		}
		// Submitted
		jQuery.ajax({url:ajaxurl, data:_postData, success:pb11_notify_callback,
		error:pb11_notify_callback, dataType:'xml', type:'POST'});
	}
	return true;
}

function show_submit()
{
	if (jQuery('#pb11_submit').length)
	{
		jQuery('#pb11_background').css('opacity', 0.0);
		jQuery('#pb11_background').show();
		jQuery('#pb11_background').fadeTo('fast', 0.5);
		jQuery('#pb11_submit').css('left',
				(jQuery(document).width() - jQuery('#pb11_submit').width()) / 2 + 'px');
		jQuery('#pb11_submit').css('top',
				(jQuery(document).height() - jQuery('#pb11_submit').height()) / 2 + 'px');
		jQuery('#pb11_submit').show();
		jQuery('#pb11_background').height(jQuery(document).height());
	}
}

jQuery(document).ready(show_submit);

