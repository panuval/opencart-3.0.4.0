//author opencartmart
var _ocm_catalog = _ocm_admin.catalog || $('select[name=\'store\'] option:selected').val();
var _ocm_request_cache = {};
var _ocm_request_counter = 1;
var xshippingpro_sub_options = {};
var xshippingpro_sub_options_flag = true;
var abortSave = false;
var isOC4 = _ocm_admin.version.indexOf('4.') == 0;
var api_token = typeof _ocm_api_token !== 'undefined' ? _ocm_api_token : ''; // for OC <= 2.3, token are taken from javascript token

var get_method_url = _ocm_catalog + 'index.php?route=api/order/get_sup_options';
var update_method_url = _ocm_catalog + 'index.php?route=api/order/update_sup_options';

if (isOC4) {
    get_method_url =  'index.php?route=sale/order' + _ocm_admin.divider + 'call&action=order' + _ocm_admin.divider + 'get_sup_options';
    update_method_url = 'index.php?route=sale/order' + _ocm_admin.divider + 'call&action=order' + _ocm_admin.divider + 'update_sup_options';
}

$('#input-shipping-method').on('change', function(event, ocmManual) {
    if (!ocmManual && !$.isEmptyObject(xshippingpro_sub_options)) {
       setSubOptions();
    }
});

function isSubOption(code) {
    return /xshippingpro\.xshippingpro\d+_\d+/.test(code);
}

function setSubOptions() {
    var code = $('input[name="shipping_method"]:checked').val() || $('#input-shipping-method').val();
    if (isSubOption(code)) {
        var api_token_2x = typeof token !== 'undefined' && '&token=' + token;
        $.post(update_method_url + (api_token || api_token_2x), {xshippingpro_code: code}, function( ) {
           if (abortSave) {
               if ($('input[name="shipping_method"]').length) { // oc 4.x
                    $('#button-shipping-method').trigger('click', ['ocmManual']);
               } else {
                    $('#input-shipping-method').trigger('change', ['ocmManual']); 
               }
               abortSave = false;
           }
        });
    }    
}
// following will be common for all mods
$.ajaxPrefilter(_onOcmAjaxReq);
$(document).ajaxComplete(_onOcmAjaxReqComplete);
/* OC v4.0.2.x */
$(document).on('click', '#form-shipping-method #button-shipping-method', function(e, ocmManual) {
    var code = $('input[name="shipping_method"]:checked').val() || $('#input-shipping-method').val();
    if (!ocmManual && isSubOption(code)) {
        e.stopPropagation();
        e.preventDefault();
        abortSave = true;
        setSubOptions();
    }
});
/* end of OC v4.0.2.x */
function _onOcmAjaxReqComplete(event, xhr, settings) {
    if (xshippingpro_sub_options_flag) {
        var api_token_2x = typeof token !== 'undefined' && '&token=' + token;
         $.get(get_method_url + (api_token || api_token_2x), function(json) {
            xshippingpro_sub_options = json.sub_options;
         });
         xshippingpro_sub_options_flag = false;  
    }
}
function _onOcmAjaxSuccess(data, status, jqXhr) {
    var xshippingpro_quote = parseAndGetData(data, 'shipping_methods.xshippingpro.quote');
    if (xshippingpro_quote) {
        var newXshippingpro = {};
        for (var key in xshippingpro_quote) {
            var tab_id = key.replace('xshippingpro', '');
            newXshippingpro[key] = xshippingpro_quote[key]; // to reset the order properly it needs to re-assign
            if (xshippingpro_sub_options[tab_id]) {
                for (var _key in xshippingpro_sub_options[tab_id]) {
                    var sub_option = xshippingpro_sub_options[tab_id][_key];
                    var code = sub_option.code.replace('xshippingpro.', '');
                    var title = '&nbsp;&nbsp; - &nbsp;' + sub_option.title;
                    newXshippingpro[code] = {
                       code: sub_option.code,
                       title: title,
                       name: title,
                       text: ''
                    };
                }
            }
        }
        if (!$.isEmptyObject(xshippingpro_sub_options)) {
            setTimeout(setSubOptions, 500);
        }
    }
    if (_ocm_request_cache[jqXhr.xid]) {
        if (xshippingpro_quote) {
            data['shipping_methods']['xshippingpro']['quote'] = newXshippingpro;
        }
        _ocm_request_cache[jqXhr.xid].call(null, data, status, jqXhr);
        _ocm_request_cache[jqXhr.xid] = null;
    }
}
function _onOcmAjaxReq(options, originalOptions, jqXhr) {
    if (!abortSave
        && isOC4 
        && !$.isEmptyObject(xshippingpro_sub_options)
        && options.url.indexOf('sale/shipping_method'+_ocm_admin.divider+'save') !== -1 
        && isSubOption(options.data)) {
            jqXhr.abort();
            abortSave = true;
            return;
    }
    if (options.dataType == 'json' || !options.dataType) {
        jqXhr.xid = _ocm_request_counter++;
        _ocm_request_cache[jqXhr.xid] = options.success;
        options.success = _onOcmAjaxSuccess;
    }
}
function parseAndGetData(data, keys) {
    var _keys = keys.split('.');
    var _data = data;
    if (!data || !$.isPlainObject(data)) {
       return false;
    }
    for (var i = 0; i < _keys.length; i++) {
        var key = _keys[i];
        if (_data[key]) {
            _data = _data[key];    
        } else {
           return false;
        }
    }
    return _data;
}