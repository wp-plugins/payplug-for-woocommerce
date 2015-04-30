jQuery('input[type="submit"]').click(function(e){
    e.preventDefault();
    jQuery(window).block({
        message: PayPlugJSParams.checking,
        baseZ: 99999,
        overlayCSS:
        {
            background: "#fff",
            opacity: 0.6
        },
        css: {
            padding:        "20px",
            zindex:         "9999999",
            textAlign:      "center",
            color:          "#555",
            border:         "3px solid #aaa",
            backgroundColor:"#fff",
            cursor:         "wait",
            lineHeight:     "24px",
            fontWeight:     "bold",
            position:       "fixed"
        }
    });

    jQuery.post( PayPlugJSParams.url, { login: jQuery("input[name='woocommerce_woopayplug_payplug_login']").val(), password: jQuery("input[name='woocommerce_woopayplug_payplug_password']").val(), test_mode: jQuery("input[name='woocommerce_woopayplug_test_mode']").is(':checked')})
    .done(function(data) {
        if(data == "errorconnexion"){
            jQuery(window).unblock();
            alert(PayPlugJSParams.error_connecting);
        }else if(data == "errorlogin"){
            jQuery(window).unblock();
            alert(PayPlugJSParams.error_login);
        }else{
            try{
                jQuery.parseJSON(data);
                jQuery("input[name='woocommerce_woopayplug_payplug_parameters']").val(encodeURI(data));
                jQuery("input[name='woocommerce_woopayplug_payplug_password']").val('');
                jQuery("#mainform").submit();
            }
            catch(err){
                jQuery(window).unblock();
                alert(PayPlugJSParams.error_connecting);
            }
        }
    })
    .fail(function() {
        jQuery(window).unblock();
        alert(PayPlugJSParams.error_unknown);
    });
        
})